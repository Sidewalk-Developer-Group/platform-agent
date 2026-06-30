<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use SidewalkDevelopers\PlatformAgent\Credentials\CredentialStore;
use SidewalkDevelopers\PlatformAgent\Restore\Push\RestorePushSubscriber;
use SidewalkDevelopers\PlatformAgent\Restore\Push\RestoreSignal;

/*
|--------------------------------------------------------------------------
| platform-agent:listen — restore push listener + poll fallback (PA5)
|--------------------------------------------------------------------------
*/

/** A subscriber that fires a scripted list of signals once, then returns. */
class FakeRestorePushSubscriber implements RestorePushSubscriber
{
    /** @param array<int, RestoreSignal> $signals */
    public function __construct(private array $signals)
    {
    }

    public function listen(callable $onSignal): void
    {
        foreach ($this->signals as $signal) {
            if (! $onSignal($signal)) {
                return;
            }
        }
    }
}

function enrolForListen(): void
{
    app(CredentialStore::class)->putRuntimeToken('runtime-pat-fixture', [
        'abilities' => ['app:restore'],
    ]);
}

function listenDir(): string
{
    $dir = sys_get_temp_dir().'/pa-listen-'.bin2hex(random_bytes(6));
    mkdir($dir, 0o755, true);

    return $dir;
}

function fakeListenContract(string $bytes): void
{
    $manifest = test()->fixtureBody('restore-jobs.download.json');
    $manifest['data']['archive']['sha256'] = hash('sha256', $bytes);
    $manifest['data']['archive']['size_bytes'] = strlen($bytes);
    $manifest['data']['download_url'] = 'https://hub.platform.test/origin-egress/archive.zip?signature=abc';

    Http::fake([
        'https://hub.platform.test/api/v1/agent/restore-jobs' => Http::response(test()->fixtureBody('restore-jobs.index.json'), 200),
        '*/agent/restore-jobs/*/download' => Http::response($manifest, 200),
        '*/origin-egress/archive.zip*' => Http::response($bytes, 200),
        '*/agent/restore-jobs/*/report' => Http::response(test()->fixtureBody('restore-jobs.report.success.json'), 200),
    ]);
}

it('refuses to listen before enrollment', function () {
    $this->artisan('platform-agent:listen', ['location' => listenDir()])
        ->expectsOutputToContain('Not enrolled')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

it('requires a target location', function () {
    enrolForListen();
    config()->set('platform-agent.restore.default_location', null);

    $this->artisan('platform-agent:listen')
        ->expectsOutputToContain('No restore target')
        ->assertExitCode(1);
});

it('runs a single poll sweep and exits when push is disabled', function () {
    enrolForListen();
    config()->set('platform-agent.restore.push.enabled', false);
    Http::fake([
        '*/agent/restore-jobs' => Http::response(['success' => true, 'data' => ['restore_jobs' => []]], 200),
    ]);

    $this->artisan('platform-agent:listen', ['location' => listenDir()])
        ->expectsOutputToContain('Restore push disabled')
        ->assertExitCode(0);
});

it('drains a single poll sweep with --once even when push is enabled', function () {
    enrolForListen();
    config()->set('platform-agent.restore.push.enabled', true);
    fakeListenContract(str_repeat('ONCE', 256));

    $this->artisan('platform-agent:listen', ['location' => listenDir(), '--once' => true])
        ->expectsOutputToContain('Single poll sweep complete')
        ->assertExitCode(0);
});

it('drains on a push restore signal when subscribed', function () {
    enrolForListen();
    config()->set('platform-agent.restore.push.enabled', true);
    fakeListenContract(str_repeat('PUSH', 256));

    // Swap the real Pusher subscriber for one that fires a single restore push.
    app()->bind(RestorePushSubscriber::class, fn () => new FakeRestorePushSubscriber([
        RestoreSignal::restore('5f6a7b8c-9d0e-4f1a-8b2c-3d4e5f6a7b8c'),
    ]));

    $this->artisan('platform-agent:listen', ['location' => listenDir()])
        ->expectsOutputToContain('Sweep:')
        ->assertExitCode(0);

    Http::assertSent(fn ($r) => str_contains($r->url(), '/report') && $r['success'] === true);
});

it('aborts the listener on a 426 upgrade-required hard block', function () {
    enrolForListen();
    config()->set('platform-agent.restore.push.enabled', true);
    Http::fake([
        '*/agent/restore-jobs' => Http::response(['message' => 'Upgrade required'], 426),
    ]);

    $this->artisan('platform-agent:listen', ['location' => listenDir()])
        ->expectsOutputToContain('upgrade required')
        ->assertExitCode(1);
});
