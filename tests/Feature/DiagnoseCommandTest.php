<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use SidewalkDevelopers\PlatformAgent\Credentials\CredentialStore;
use SidewalkDevelopers\PlatformAgent\PlatformAgent;
use SidewalkDevelopers\PlatformAgent\State\AgentStateStore;

/**
 * Everything a healthy install looks like: enrolled, schedule wired, fresh
 * scheduler marker, spatie sources present, writable temp disk, Hub faked.
 * Individual tests then break ONE dimension.
 */
function greenDiagnose(?array $heartbeatBody = null, int $heartbeatStatus = 200): void
{
    app(CredentialStore::class)->putRuntimeToken('runtime-pat-fixture', [
        'abilities' => ['app:backup', 'app:heartbeat', 'app:restore'],
    ]);

    PlatformAgent::schedule(app(Schedule::class));
    app(AgentStateStore::class)->recordScheduledHeartbeat();

    config()->set('backup.backup.source.databases', ['sqlite']);
    config()->set('backup.backup.source.files.include', [base_path()]);

    Storage::fake('local');

    Http::fake([
        '*/api/v1/agent/heartbeat' => Http::response(
            $heartbeatBody ?? json_decode((string) file_get_contents(__DIR__.'/../Fixtures/hub-contract/heartbeat.success.json'), true, 512, JSON_THROW_ON_ERROR)['body'],
            $heartbeatStatus,
        ),
        '*' => Http::response('', 200),
    ]);
}

it('reports an absent runtime token and redacts the enrollment token', function () {
    Http::fake(['*' => Http::response('', 200)]);

    Artisan::call('platform-agent:diagnose');
    $output = Artisan::output();

    expect($output)->toContain('absent')
        ->and($output)->toContain('platform-agent:install')
        // The enrollment token secret is never printed in full.
        ->and($output)->not->toContain('enrollment-token-fixture');
});

it('reports a present runtime token without ever printing the secret', function () {
    app(CredentialStore::class)->putRuntimeToken('2|SUPER-SECRET-RUNTIME', [
        'token_id' => '4d5e6f7a-8b9c-4d0e-1f2a-3b4c5d6e7f8a',
        'abilities' => ['app:backup', 'app:heartbeat', 'app:restore'],
        'expires_at' => null,
    ]);

    Http::fake(['*' => Http::response('', 200)]);

    Artisan::call('platform-agent:diagnose');
    $output = Artisan::output();

    expect($output)->toContain('present, encrypted at rest')
        ->and($output)->toContain('app:backup')
        ->and($output)->not->toContain('SUPER-SECRET-RUNTIME');
});

it('reports connectivity to the Cloud Hub', function () {
    Http::fake(['*' => Http::response('', 200)]);

    Artisan::call('platform-agent:diagnose');

    expect(Artisan::output())->toContain('reachable');
});

it('passes every doctor check on a fully-wired install', function () {
    greenDiagnose();

    $exit = Artisan::call('platform-agent:diagnose');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('All checks passed')
        ->and($output)->toContain('accepted by the Hub')
        ->and($output)->not->toContain('FAIL');
});

it('FAILS loudly (exit 1) when the schedule one-liner was never wired', function () {
    greenDiagnose();
    // Un-wire: a fresh Schedule instance with no entries.
    app()->instance(Schedule::class, new Schedule);

    $exit = Artisan::call('platform-agent:diagnose');
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('BACKUPS WILL NOT RUN')
        ->and($output)->toContain('PlatformAgent::schedule');
});

it('surfaces the live soft version warning as a WARN (still passes)', function () {
    $body = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/hub-contract/heartbeat.success.json'), true, 512, JSON_THROW_ON_ERROR)['body'];
    $body['data']['version_warning'] = 'Agent 1.4.0 is below the recommended minimum 1.6.0.';

    greenDiagnose($body);

    $exit = Artisan::call('platform-agent:diagnose');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('Soft version lag')
        ->and($output)->toContain('recommended minimum 1.6.0');
});

it('FAILS on a live 426 hard block', function () {
    greenDiagnose([
        'success' => false,
        'message' => 'Agent version 1.4.0 is below the minimum compatible version 2.0.0. Upgrade required.',
        'data' => null,
        'errors' => null,
        'meta' => [],
    ], 426);

    $exit = Artisan::call('platform-agent:diagnose');
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('HARD-BLOCKED')
        ->and($output)->toContain('Upgrade required');
});

it('warns when the scheduled heartbeat marker is stale (dead schedule:run cron)', function () {
    greenDiagnose();
    app(AgentStateStore::class)->recordScheduledHeartbeat(new DateTimeImmutable('-2 hours'));

    $exit = Artisan::call('platform-agent:diagnose');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('appears DEAD');
});

it('warns when the scheduled heartbeat has never run', function () {
    greenDiagnose();
    // Wipe the marker greenDiagnose recorded.
    Illuminate\Support\Facades\Schema::dropIfExists('platform_agent_state');

    Artisan::call('platform-agent:diagnose');

    expect(Artisan::output())->toContain('schedule:run');
});

it('FAILS when the temp disk is unusable', function () {
    greenDiagnose();
    config()->set('platform-agent.backup.temp_disk', 'not-a-disk');

    $exit = Artisan::call('platform-agent:diagnose');
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('not usable');
});

it('FAILS when the spatie backup config is missing entirely', function () {
    greenDiagnose();
    config()->set('backup.backup', null);

    $exit = Artisan::call('platform-agent:diagnose');
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('spatie/laravel-backup');
});

it('warns when a kind has no spatie source configured', function () {
    greenDiagnose();
    config()->set('backup.backup.source.databases', []);

    $exit = Artisan::call('platform-agent:diagnose');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('DATABASE backups will fail');
});
