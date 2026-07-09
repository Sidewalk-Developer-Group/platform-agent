<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use SidewalkDevelopers\PlatformAgent\Credentials\CredentialStore;
use SidewalkDevelopers\PlatformAgent\State\AgentStateStore;

function enrolForReport(): void
{
    app(CredentialStore::class)->putRuntimeToken('runtime-pat-fixture', [
        'abilities' => ['app:backup', 'app:heartbeat', 'app:restore'],
    ]);
}

it('rejects an invalid --status before sending anything', function () {
    enrolForReport();

    $this->artisan('platform-agent:report --status=on-fire')
        ->expectsOutputToContain('Invalid --status')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

it('refuses to report before enrollment', function () {
    $this->artisan('platform-agent:report')
        ->expectsOutputToContain('Not enrolled')
        ->assertExitCode(1);
});

it('sends a richer report with the chosen status and runtime bearer', function () {
    enrolForReport();

    Http::fake([
        '*/api/v1/agent/report' => Http::response($this->fixtureBody('report.success.json'), 200),
    ]);

    $this->artisan('platform-agent:report --status=degraded')
        ->expectsOutputToContain('Report delivered')
        ->assertExitCode(0);

    Http::assertSent(function (Request $request) {
        return $request->hasHeader('Authorization', 'Bearer runtime-pat-fixture')
            && $request['status'] === 'degraded'
            && is_array($request['metadata'])
            && array_key_exists('os', $request['metadata'])
            && array_key_exists('queue_connection', $request['metadata'])
            && ! array_key_exists('usage_percentage', $request->data());
    });
});

it('defaults to a computed (healthy) status', function () {
    enrolForReport();

    Http::fake([
        '*/api/v1/agent/report' => Http::response($this->fixtureBody('report.success.json'), 200),
    ]);

    $this->artisan('platform-agent:report')->assertExitCode(0);

    Http::assertSent(fn (Request $request) => $request['status'] === 'healthy');
});

it('computes degraded on --status=auto after a failed backup run', function () {
    enrolForReport();
    app(AgentStateStore::class)->recordBackupRun('database', success: false);

    Http::fake([
        '*/api/v1/agent/report' => Http::response($this->fixtureBody('report.success.json'), 200),
    ]);

    $this->artisan('platform-agent:report')->assertExitCode(0);

    Http::assertSent(fn (Request $request) => $request['status'] === 'degraded'
        && in_array('last_database_backup_failed', $request['metadata']['status_reasons'] ?? [], true));
});

it('lets an explicit --status override the computed value', function () {
    enrolForReport();
    app(AgentStateStore::class)->recordBackupRun('database', success: false); // computed would be degraded

    Http::fake([
        '*/api/v1/agent/report' => Http::response($this->fixtureBody('report.success.json'), 200),
    ]);

    $this->artisan('platform-agent:report --status=healthy')->assertExitCode(0);

    Http::assertSent(fn (Request $request) => $request['status'] === 'healthy');
});

it('sends real telemetry fields on report too', function () {
    enrolForReport();
    app(AgentStateStore::class)->recordBackupRun('files', success: true, finishedAt: new DateTimeImmutable('2026-07-10 02:00:00 +00:00'));

    Http::fake([
        '*/api/v1/agent/report' => Http::response($this->fixtureBody('report.success.json'), 200),
    ]);

    $this->artisan('platform-agent:report')->assertExitCode(0);

    Http::assertSent(fn (Request $request) => $request['last_backup_at'] === '2026-07-10T02:00:00+00:00'
        && is_int($request['storage_usage_bytes'])
        && is_int($request['metadata']['disk_free_bytes'] ?? null));
});
