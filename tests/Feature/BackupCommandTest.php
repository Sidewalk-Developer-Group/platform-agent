<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use SidewalkDevelopers\PlatformAgent\Backup\BackupResult;
use SidewalkDevelopers\PlatformAgent\Backup\BackupRunner;
use SidewalkDevelopers\PlatformAgent\Credentials\CredentialStore;
use SidewalkDevelopers\PlatformAgent\State\AgentStateStore;

function enrolForBackup(): void
{
    app(CredentialStore::class)->putRuntimeToken('runtime-pat-fixture', [
        'abilities' => ['app:backup', 'app:heartbeat', 'app:restore'],
    ]);
}

/**
 * Bind a fake BackupRunner that writes a real temp archive of $bytes (so the
 * command can checksum + "upload" it), or fails. Returns the produced path.
 */
function fakeRunner(int $bytes = 1024, bool $ok = true, string $error = 'boom'): string
{
    $path = tempnam(sys_get_temp_dir(), 'pa-backup-').'.zip';
    file_put_contents($path, str_repeat('A', $bytes));

    app()->instance(BackupRunner::class, new class($ok, $path, $bytes, $error) implements BackupRunner
    {
        public function __construct(
            private bool $ok,
            private string $path,
            private int $bytes,
            private string $error,
        ) {}

        public function run(string $kind, string $spatieName, string $tempDisk): BackupResult
        {
            return $this->ok
                ? BackupResult::success($this->path, $this->bytes, $spatieName)
                : BackupResult::failed($this->error, $spatieName);
        }
    });

    return $path;
}

it('refuses to back up before enrollment (no runtime token)', function () {
    fakeRunner();

    $this->artisan('platform-agent:backup --kind=database')
        ->expectsOutputToContain('Not enrolled')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

it('rejects an invalid --kind', function () {
    enrolForBackup();

    $this->artisan('platform-agent:backup --kind=everything')
        ->expectsOutputToContain('Invalid --kind')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

it('runs a split DB backup: running START, single-POST archive, terminal success', function () {
    enrolForBackup();
    $path = fakeRunner(2048);

    Http::fake([
        '*/api/v1/agent/backup-runs' => Http::response($this->fixtureBody('backup-runs.success.response.planned.json'), 201),
        '*/api/v1/agent/archives' => Http::response($this->fixtureBody('archives.success.json'), 201),
    ]);

    $this->artisan('platform-agent:backup --kind=database')
        ->expectsOutputToContain('uploaded + verified')
        ->assertExitCode(0);

    // running START
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/agent/backup-runs')
        && $r['status'] === 'running'
        && $r['kind'] === 'database'
        && $r->hasHeader('Authorization', 'Bearer runtime-pat-fixture'));

    // single-POST archive (below default threshold) — multipart, never sends application_id
    Http::assertSent(function (Request $r) {
        if (! str_ends_with($r->url(), '/agent/archives')) {
            return false;
        }
        $fields = collect($r->data())->pluck('contents', 'name'); // multipart parts -> name=>contents

        return $r->isMultipart()
            && $fields->get('kind') === 'database'
            && preg_match('/^[a-f0-9]{64}$/', (string) $fields->get('checksum')) === 1
            && ! $fields->has('application_id');
    });

    // terminal success links the archive id from the catalog response
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/agent/backup-runs')
        && $r['status'] === 'success'
        && $r['backup_archive_id'] === '2b3c4d5e-6f7a-4b8c-9d0e-1f2a3b4c5d6e'
        && $r['size_bytes'] === 2048);

    expect(is_file($path))->toBeFalse();          // temp archive cleaned up
    expect(is_file($path.'.sha256'))->toBeFalse(); // sidecar cleaned up

    // The local state now feeds last_backup_at on the next heartbeat.
    $state = app(AgentStateStore::class);
    expect($state->lastSuccessfulBackupAt())->not->toBeNull()
        ->and($state->failedBackupKinds())->toBe([]);
});

it('routes a large archive to the tus surface', function () {
    enrolForBackup();
    config()->set('platform-agent.backup.tus.threshold_bytes', 1024); // force tus
    fakeRunner(4096);

    Http::fake([
        '*/api/v1/agent/backup-runs' => Http::response($this->fixtureBody('backup-runs.success.response.planned.json'), 201),
        '*/api/v1/agent/uploads' => Http::response('', 201, ['Location' => 'https://hub.platform.test/api/v1/agent/uploads/upl_1']),
        'https://hub.platform.test/api/v1/agent/uploads/upl_1' => Http::response('', 204, [
            'Upload-Offset' => '4096',
            'X-Backup-Archive-Id' => '2b3c4d5e-6f7a-4b8c-9d0e-1f2a3b4c5d6e',
            'X-Backup-Checksum-Status' => 'verified',
        ]),
    ]);

    $this->artisan('platform-agent:backup --kind=files')
        ->expectsOutputToContain('tus')
        ->assertExitCode(0);

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/agent/uploads')
        && $r->method() === 'POST'
        && $r->hasHeader('Tus-Resumable', '1.0.0')
        && $r->hasHeader('Upload-Length', '4096'));
});

it('reports a FAILED run and exits non-zero when spatie fails', function () {
    enrolForBackup();
    fakeRunner(0, ok: false, error: 'Database dump failed: password=topsecret');

    Http::fake([
        '*/api/v1/agent/backup-runs' => Http::response($this->fixtureBody('backup-runs.success.response.planned.json'), 201),
    ]);

    $this->artisan('platform-agent:backup --kind=database')
        ->expectsOutputToContain('backup failed')
        ->assertExitCode(1);

    // failure reported, secret redacted, archives endpoint never touched
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/agent/backup-runs')
        && $r['status'] === 'failed'
        && str_contains((string) $r['error_message'], '[redacted]')
        && ! str_contains((string) $r['error_message'], 'topsecret'));

    Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/agent/archives'));

    // The failure is recorded locally → the next heartbeat computes degraded.
    expect(app(AgentStateStore::class)->failedBackupKinds())->toBe(['database']);
});

it('treats a Hub corrupted verdict as a FAILED run (Rule 4)', function () {
    enrolForBackup();
    fakeRunner(2048);

    $corrupted = $this->fixtureBody('archives.success.json');
    $corrupted['data']['status'] = 'corrupted';
    $corrupted['data']['is_verified'] = false;
    $corrupted['data']['is_corrupted'] = true;

    Http::fake([
        '*/api/v1/agent/backup-runs' => Http::response($this->fixtureBody('backup-runs.success.response.planned.json'), 201),
        '*/api/v1/agent/archives' => Http::response($corrupted, 201),
    ]);

    $this->artisan('platform-agent:backup --kind=database')
        ->expectsOutputToContain('corrupted')
        ->assertExitCode(1);

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/agent/backup-runs')
        && $r['status'] === 'failed'
        && str_contains((string) $r['error_message'], 'corrupted'));
});
