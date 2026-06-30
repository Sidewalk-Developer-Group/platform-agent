<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use SidewalkDevelopers\PlatformAgent\Credentials\CredentialStore;
use SidewalkDevelopers\PlatformAgent\Http\PlatformClient;
use SidewalkDevelopers\PlatformAgent\Restore\Push\PusherRestoreSubscriber;
use SidewalkDevelopers\PlatformAgent\Restore\Push\RestoreSignal;
use SidewalkDevelopers\PlatformAgent\Restore\Push\WebSocketConnector;

/*
|--------------------------------------------------------------------------
| PusherRestoreSubscriber — Pusher-protocol restore push (PA5)
|--------------------------------------------------------------------------
*/

/** A scripted WebSocket transport: replays canned server frames, records sends. */
class FakeWebSocketConnector implements WebSocketConnector
{
    /** @var array<int, string> */
    public array $sent = [];

    public bool $connected = false;

    public ?string $connectedUrl = null;

    public bool $throwOnConnect = false;

    /** @param array<int, string> $inbox */
    public function __construct(private array $inbox = [])
    {
    }

    public function connect(string $url, float $timeout): void
    {
        if ($this->throwOnConnect) {
            throw new RuntimeException('connect refused');
        }
        $this->connected = true;
        $this->connectedUrl = $url;
    }

    public function send(string $payload): void
    {
        $this->sent[] = $payload;
    }

    public function receive(float $timeout): ?string
    {
        if ($this->inbox === []) {
            $this->connected = false;

            return null;
        }

        return array_shift($this->inbox);
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function close(): void
    {
        $this->connected = false;
    }
}

function makeSubscriber(FakeWebSocketConnector $connector): PusherRestoreSubscriber
{
    app(CredentialStore::class)->putRuntimeToken('runtime-pat-fixture', [
        'abilities' => ['app:restore'],
    ]);

    config()->set('platform-agent.restore.push.key', 'reverb-key');
    config()->set('platform-agent.restore.push.poll_fallback_seconds', 1);

    return new PusherRestoreSubscriber(
        connector: $connector,
        client: app(PlatformClient::class),
        config: (array) config('platform-agent'),
    );
}

function established(string $socketId = '123.456'): string
{
    return (string) json_encode([
        'event' => 'pusher:connection_established',
        'data' => json_encode(['socket_id' => $socketId, 'activity_timeout' => 30]),
    ]);
}

it('authorizes the private channel and emits a restore signal on a broadcast', function () {
    Http::fake([
        '*/agent/broadcasting/auth' => Http::response(['data' => ['auth' => 'reverb-key:sig']], 200),
    ]);

    $connector = new FakeWebSocketConnector([
        established(),
        (string) json_encode([
            'event' => 'restore.requested',
            'data' => json_encode(['restore_job_id' => 'job-xyz']),
        ]),
    ]);

    $signals = [];
    makeSubscriber($connector)->listen(function (RestoreSignal $s) use (&$signals): bool {
        $signals[] = $s;

        return ! $s->isRestore(); // stop once we get the restore push
    });

    $restore = collect($signals)->first(fn (RestoreSignal $s) => $s->isRestore());
    expect($restore)->not->toBeNull()
        ->and($restore->jobId)->toBe('job-xyz');

    // It subscribed to the per-Application PRIVATE channel with the Hub auth.
    $subscribe = collect($connector->sent)
        ->map(fn ($p) => json_decode($p, true))
        ->first(fn ($m) => ($m['event'] ?? null) === 'pusher:subscribe');

    expect($subscribe['data']['channel'])
        ->toBe('private-applications.'.config('platform-agent.application_uuid'))
        ->and($subscribe['data']['auth'])->toBe('reverb-key:sig');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'agent/broadcasting/auth'));
});

it('answers a pusher ping with a pong', function () {
    Http::fake(['*/agent/broadcasting/auth' => Http::response(['auth' => 'reverb-key:sig'], 200)]);

    $connector = new FakeWebSocketConnector([
        established(),
        (string) json_encode(['event' => 'pusher:ping', 'data' => []]),
        (string) json_encode(['event' => 'restore.requested', 'data' => json_encode([])]),
    ]);

    makeSubscriber($connector)->listen(fn (RestoreSignal $s): bool => ! $s->isRestore());

    $events = collect($connector->sent)->map(fn ($p) => json_decode($p, true)['event'] ?? null);
    expect($events)->toContain('pusher:pong');
});

it('falls back to a poll signal when the connection cannot be established', function () {
    Http::fake();

    $connector = new FakeWebSocketConnector();
    $connector->throwOnConnect = true;

    $signals = [];
    makeSubscriber($connector)->listen(function (RestoreSignal $s) use (&$signals): bool {
        $signals[] = $s;

        return false;
    });

    expect($signals)->toHaveCount(1)
        ->and($signals[0]->isPoll())->toBeTrue();

    Http::assertNothingSent(); // never tried to auth a channel it could not open
});

it('emits a poll fallback tick when the socket idles', function () {
    Http::fake(['*/agent/broadcasting/auth' => Http::response(['auth' => 'reverb-key:sig'], 200)]);

    // Only the handshake frame — receive() then returns null (idle/closed).
    $connector = new FakeWebSocketConnector([established()]);

    $signals = [];
    makeSubscriber($connector)->listen(function (RestoreSignal $s) use (&$signals): bool {
        $signals[] = $s;

        return false; // stop on the first idle tick
    });

    expect($signals)->toHaveCount(1)
        ->and($signals[0]->isPoll())->toBeTrue();
});
