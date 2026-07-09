<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use SidewalkDevelopers\PlatformAgent\Credentials\CredentialStore;
use SidewalkDevelopers\PlatformAgent\State\AgentStateStore;

function enrolAgent(): void
{
    app(CredentialStore::class)->putRuntimeToken('runtime-pat-fixture', [
        'abilities' => ['app:backup', 'app:heartbeat', 'app:restore'],
    ]);
}

it('refuses to heartbeat before enrollment (no runtime token)', function () {
    $this->artisan('platform-agent:heartbeat')
        ->expectsOutputToContain('Not enrolled')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

it('sends a bytes-only heartbeat with the runtime bearer', function () {
    enrolAgent();

    Http::fake([
        '*/api/v1/agent/heartbeat' => Http::response($this->fixtureBody('heartbeat.success.json'), 200),
    ]);

    $this->artisan('platform-agent:heartbeat')
        ->expectsOutputToContain('Heartbeat delivered')
        ->assertExitCode(0);

    Http::assertSent(function (Request $request) {
        return $request->hasHeader('Authorization', 'Bearer runtime-pat-fixture')
            && $request['status'] === 'healthy'
            && $request['agent_version'] === '1.4.0'
            // Rule 1: bytes only — never a usage percentage on the wire.
            && ! array_key_exists('usage_percentage', $request->data())
            && ! array_key_exists('storage_usage_percentage', $request->data());
    });
});

it('carries real telemetry: last_backup_at, storage bytes and disk facts', function () {
    enrolAgent();

    // A real successful run recorded locally + a measurable storage tree.
    app(AgentStateStore::class)->recordBackupRun('database', success: true, finishedAt: new DateTimeImmutable('2026-07-10 03:00:00 +00:00'));

    $dir = sys_get_temp_dir().'/pa-hb-telemetry-'.uniqid();
    mkdir($dir, 0755, true);
    file_put_contents($dir.'/data.bin', str_repeat('z', 256));
    config()->set('platform-agent.telemetry.storage_paths', [$dir]);

    Http::fake([
        '*/api/v1/agent/heartbeat' => Http::response($this->fixtureBody('heartbeat.success.json'), 200),
    ]);

    $this->artisan('platform-agent:heartbeat')->assertExitCode(0);

    Http::assertSent(function (Request $request) {
        return $request['status'] === 'healthy'
            && $request['last_backup_at'] === '2026-07-10T03:00:00+00:00'
            && $request['storage_usage_bytes'] === 256
            && is_int($request['metadata']['disk_free_bytes'] ?? null)
            && is_int($request['metadata']['disk_total_bytes'] ?? null)
            // Rule 1: bytes only, still no percentage anywhere.
            && ! array_key_exists('usage_percentage', $request->data());
    });
});

it('sends a computed degraded status after a failed backup run', function () {
    enrolAgent();

    app(AgentStateStore::class)->recordBackupRun('files', success: false);

    Http::fake([
        '*/api/v1/agent/heartbeat' => Http::response($this->fixtureBody('heartbeat.success.json'), 200),
    ]);

    $this->artisan('platform-agent:heartbeat')->assertExitCode(0);

    Http::assertSent(fn (Request $request) => $request['status'] === 'degraded'
        && in_array('last_files_backup_failed', $request['metadata']['status_reasons'] ?? [], true));
});

it('warns but still succeeds on a soft version_warning', function () {
    enrolAgent();

    $body = $this->fixtureBody('heartbeat.success.json');
    $body['data']['version_warning'] = 'Agent 1.4.0 is below the recommended minimum 1.6.0.';

    Http::fake([
        '*/api/v1/agent/heartbeat' => Http::response($body, 200),
    ]);

    $this->artisan('platform-agent:heartbeat')
        ->expectsOutputToContain('Version notice')
        ->assertExitCode(0);
});

it('fails on a 422 validation error', function () {
    enrolAgent();

    Http::fake([
        '*/api/v1/agent/heartbeat' => Http::response($this->fixtureBody('heartbeat.validation-error.json'), 422),
    ]);

    $this->artisan('platform-agent:heartbeat')
        ->expectsOutputToContain('Heartbeat rejected')
        ->assertExitCode(1);
});

it('hard-blocks on a 426 upgrade-required', function () {
    enrolAgent();

    Http::fake([
        '*/api/v1/agent/heartbeat' => Http::response([
            'success' => false,
            'message' => 'Agent version 1.4.0 is below the minimum compatible version 2.0.0. Upgrade required.',
            'data' => null,
            'errors' => null,
            'meta' => [],
        ], 426),
    ]);

    $this->artisan('platform-agent:heartbeat')
        ->expectsOutputToContain('upgrade required')
        ->assertExitCode(1);
});
