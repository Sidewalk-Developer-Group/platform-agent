<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use SidewalkDevelopers\PlatformAgent\Credentials\CredentialStore;

/*
|--------------------------------------------------------------------------
| platform-agent:restore — agent-PULL restore (PA4 / ADR-0011)
|--------------------------------------------------------------------------
*/

function enrolForRestore(): void
{
    app(CredentialStore::class)->putRuntimeToken('runtime-pat-fixture', [
        'abilities' => ['app:backup', 'app:heartbeat', 'app:restore'],
    ]);
}

/** A fresh, empty deposit directory for the test. */
function restoreTargetDir(): string
{
    $dir = sys_get_temp_dir().'/pa-restore-dest-'.bin2hex(random_bytes(6));
    mkdir($dir, 0o755, true);

    return $dir;
}

/**
 * Fake the full Hub restore contract. The manifest sha256 + download_url are
 * overridden to match the faked archive bytes, so the agent's Rule-4 verify
 * passes (or, when $tamper is true, fails).
 *
 * @return array{bytes: string, dir: string}
 */
function fakeRestoreContract(string $bytes, bool $tamper = false): array
{
    $manifest = test()->fixtureBody('restore-jobs.download.json');
    $manifest['data']['archive']['sha256'] = $tamper
        ? str_repeat('0', 64)
        : hash('sha256', $bytes);
    $manifest['data']['archive']['size_bytes'] = strlen($bytes);
    $manifest['data']['download_url'] = 'https://hub.platform.test/origin-egress/archive.zip?signature=abc';

    Http::fake([
        'https://hub.platform.test/api/v1/agent/restore-jobs' => Http::response(test()->fixtureBody('restore-jobs.index.json'), 200),
        '*/agent/restore-jobs/*/download' => Http::response($manifest, 200),
        '*/origin-egress/archive.zip*' => Http::response($bytes, 200, ['Content-Type' => 'application/zip']),
        '*/agent/restore-jobs/*/report' => Http::response(test()->fixtureBody('restore-jobs.report.success.json'), 200),
    ]);

    return ['bytes' => $bytes, 'dir' => restoreTargetDir()];
}

it('refuses to restore before enrollment (no runtime token)', function () {
    $this->artisan('platform-agent:restore', ['location' => restoreTargetDir()])
        ->expectsOutputToContain('Not enrolled')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

it('requires a target location', function () {
    enrolForRestore();
    config()->set('platform-agent.restore.default_location', null);

    $this->artisan('platform-agent:restore')
        ->expectsOutputToContain('No restore target')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

it('pulls, verifies the SHA256 (Rule 4), deposits the archive + sidecar and reports success', function () {
    enrolForRestore();
    $bytes = str_repeat('RESTORE-ME', 256);
    $ctx = fakeRestoreContract($bytes);

    $this->artisan('platform-agent:restore', ['location' => $ctx['dir']])
        ->expectsOutputToContain('verified + deposited')
        ->assertExitCode(0);

    $deposited = $ctx['dir'].'/abc-erp-prod-db-2026-06-29-1200.zip';
    expect(is_file($deposited))->toBeTrue()
        ->and(file_get_contents($deposited))->toBe($bytes)
        ->and(is_file($deposited.'.sha256'))->toBeTrue()
        ->and(file_get_contents($deposited.'.sha256'))->toContain(hash('sha256', $bytes));

    // The byte pull carried the runtime PAT (defence in depth on the signed URL).
    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/origin-egress/archive.zip')
        && $r->hasHeader('Authorization', 'Bearer runtime-pat-fixture'));

    // Authoritative success report.
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/report')
        && $r['success'] === true);
});

it('aborts on a checksum mismatch (Rule 4): no deposit, reports failure', function () {
    enrolForRestore();
    $bytes = str_repeat('CORRUPT', 128);
    $ctx = fakeRestoreContract($bytes, tamper: true);

    $this->artisan('platform-agent:restore', ['location' => $ctx['dir']])
        ->expectsOutputToContain('CHECKSUM MISMATCH')
        ->assertExitCode(1);

    // Nothing deposited; partial removed.
    expect(glob($ctx['dir'].'/*'))->toBe([]);

    // Failure reported with a reason (no silent failure).
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/report')
        && $r['success'] === false
        && str_contains((string) $r['reason'], 'SHA256 mismatch'));
});

it('does nothing (exit 0) when there are no approved restore jobs', function () {
    enrolForRestore();

    $empty = $this->fixtureBody('restore-jobs.index.json');
    $empty['data']['count'] = 0;
    $empty['data']['restore_jobs'] = [];

    Http::fake([
        'https://hub.platform.test/api/v1/agent/restore-jobs' => Http::response($empty, 200),
    ]);

    $this->artisan('platform-agent:restore', ['location' => restoreTargetDir()])
        ->expectsOutputToContain('No approved restore jobs')
        ->assertExitCode(0);

    Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/report'));
});

it('refuses to guess when multiple jobs are approved (requires --job)', function () {
    enrolForRestore();

    $two = $this->fixtureBody('restore-jobs.index.json');
    $two['data']['count'] = 2;
    $two['data']['restore_jobs'][] = $two['data']['restore_jobs'][0];
    $two['data']['restore_jobs'][1]['id'] = '6a7b8c9d-0e1f-4a2b-8c3d-4e5f6a7b8c9d';

    Http::fake([
        'https://hub.platform.test/api/v1/agent/restore-jobs' => Http::response($two, 200),
    ]);

    $this->artisan('platform-agent:restore', ['location' => restoreTargetDir()])
        ->expectsOutputToContain('Multiple approved restore jobs')
        ->assertExitCode(1);
});

it('reports failure and exits non-zero when the byte download fails', function () {
    enrolForRestore();
    $manifest = $this->fixtureBody('restore-jobs.download.json');
    $manifest['data']['download_url'] = 'https://hub.platform.test/origin-egress/archive.zip';

    Http::fake([
        'https://hub.platform.test/api/v1/agent/restore-jobs' => Http::response($this->fixtureBody('restore-jobs.index.json'), 200),
        '*/agent/restore-jobs/*/download' => Http::response($manifest, 200),
        '*/origin-egress/archive.zip*' => Http::response('', 500),
        '*/agent/restore-jobs/*/report' => Http::response($this->fixtureBody('restore-jobs.report.failed.json'), 200),
    ]);

    $this->artisan('platform-agent:restore', ['location' => restoreTargetDir()])
        ->expectsOutputToContain('Restore failed')
        ->assertExitCode(1);

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/report') && $r['success'] === false);
});
