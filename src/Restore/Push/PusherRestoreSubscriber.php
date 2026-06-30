<?php

declare(strict_types=1);

namespace SidewalkDevelopers\PlatformAgent\Restore\Push;

use Psr\Log\LoggerInterface;
use SidewalkDevelopers\PlatformAgent\Http\PlatformClient;

/**
 * Pusher-protocol restore-discovery subscriber (PA5 / ADR-0007 Addendum B.5).
 *
 * Speaks the Pusher protocol (Reverb-compatible) over a {@see WebSocketConnector}
 * seam: connect → read `pusher:connection_established` (socket id) → authorize
 * the per-Application PRIVATE channel via the Hub `POST /api/v1/agent/broadcasting/auth`
 * (runtime PAT; the Hub binds the channel to the token's Application — the id is
 * never trusted from the client) → `pusher:subscribe` → emit a RESTORE signal on
 * the configured restore event. `pusher:ping` is answered with `pusher:pong`.
 *
 * On idle, disconnect, or any protocol error it emits a POLL signal so the
 * Rule-6 fallback sweep still runs — push NEVER replaces polling, it only
 * front-runs it. The subscriber performs no restore itself; it only signals.
 */
final class PusherRestoreSubscriber implements RestorePushSubscriber
{
    /** @var array<string, mixed> */
    private array $config;

    public function __construct(
        private readonly WebSocketConnector $connector,
        private readonly PlatformClient $client,
        array $config,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->config = $config;
    }

    public function listen(callable $onSignal): void
    {
        $push = (array) ($this->config['restore']['push'] ?? []);
        $idleTimeout = (float) max(1, (int) ($push['poll_fallback_seconds'] ?? 300));
        $connectTimeout = (float) max(1, (int) ($push['connect_timeout'] ?? 15));
        $eventName = (string) ($push['event'] ?? 'restore.requested');
        $channel = $this->resolveChannel($push);

        try {
            $this->connector->connect($this->socketUrl($push), $connectTimeout);
        } catch (\Throwable $e) {
            $this->logger?->warning('platform-agent.restore.push_connect_failed', [
                'reason' => $e->getMessage(),
            ]);

            // Never strand the agent: hand control back with a poll tick.
            $onSignal(RestoreSignal::poll('push connect failed'));

            return;
        }

        try {
            $socketId = $this->awaitConnection($connectTimeout);
            if ($socketId === null || ! $this->subscribe($channel, $socketId)) {
                $onSignal(RestoreSignal::poll('subscribe failed'));

                return;
            }

            $this->logger?->info('platform-agent.restore.push_subscribed', [
                'channel' => $channel,
                'event' => $eventName,
            ]);

            $this->loop($onSignal, $idleTimeout, $eventName);
        } finally {
            $this->connector->close();
        }
    }

    /**
     * @param  callable(RestoreSignal): bool  $onSignal
     */
    private function loop(callable $onSignal, float $idleTimeout, string $eventName): void
    {
        while ($this->connector->isConnected()) {
            $raw = $this->connector->receive($idleTimeout);

            if ($raw === null) {
                // Idle timeout or a clean close → Rule-6 safety poll, keep going
                // only while still connected (a close ends the loop next check).
                if (! $onSignal(RestoreSignal::poll('idle'))) {
                    return;
                }

                continue;
            }

            $message = $this->decode($raw);
            $event = (string) ($message['event'] ?? '');

            if ($event === 'pusher:ping') {
                $this->emit('pusher:pong', []);

                continue;
            }

            if ($event === 'pusher:error') {
                $this->logger?->warning('platform-agent.restore.push_error', [
                    'data' => $message['data'] ?? null,
                ]);
                $onSignal(RestoreSignal::poll('pusher error'));

                return;
            }

            if (! $this->isRestoreEvent($event, $eventName)) {
                continue; // connection_established echo, subscription_succeeded, other events.
            }

            $data = $this->eventData($message);
            $jobId = $this->extractJobId($data);

            if (! $onSignal(RestoreSignal::restore($jobId))) {
                return;
            }
        }
    }

    private function awaitConnection(float $timeout): ?string
    {
        $raw = $this->connector->receive($timeout);
        if ($raw === null) {
            return null;
        }

        $message = $this->decode($raw);
        if (($message['event'] ?? null) !== 'pusher:connection_established') {
            return null;
        }

        $data = $this->eventData($message);

        $socketId = $data['socket_id'] ?? null;

        return is_string($socketId) && $socketId !== '' ? $socketId : null;
    }

    private function subscribe(string $channel, string $socketId): bool
    {
        $auth = $this->client->broadcastingAuth($channel, $socketId);

        // Accept both the canonical envelope (`data.auth`) and a raw `{auth}`.
        $token = $auth['auth'] ?? ($auth['data']['auth'] ?? null);

        if (! is_string($token) || $token === '') {
            $this->logger?->warning('platform-agent.restore.push_auth_missing', [
                'channel' => $channel,
            ]);

            return false;
        }

        $this->emit('pusher:subscribe', [
            'channel' => $channel,
            'auth' => $token,
        ]);

        return true;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function emit(string $event, array $data): void
    {
        $this->connector->send((string) json_encode([
            'event' => $event,
            'data' => $data,
        ]));
    }

    /**
     * Restore broadcasts may arrive under the configured short name
     * ("restore.requested") OR a fully-qualified Laravel class/broadcastAs name.
     * Match the suffix so either Hub convention triggers a drain.
     */
    private function isRestoreEvent(string $event, string $configured): bool
    {
        if ($event === '' || str_starts_with($event, 'pusher:') || str_starts_with($event, 'pusher_internal:')) {
            return false;
        }

        return $event === $configured
            || str_ends_with($event, $configured)
            || str_contains(strtolower($event), 'restore');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function extractJobId(array $data): ?string
    {
        foreach (['restore_job_id', 'job_id', 'id'] as $key) {
            if (isset($data[$key]) && is_string($data[$key]) && $data[$key] !== '') {
                return $data[$key];
            }
        }

        return null;
    }

    /**
     * The Pusher `data` field is itself a JSON-encoded string for app events,
     * but already an array for some internal events — handle both.
     *
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    private function eventData(array $message): array
    {
        $data = $message['data'] ?? [];

        if (is_string($data)) {
            $decoded = json_decode($data, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($data) ? $data : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $raw): array
    {
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Build "private-<channel>" from config, substituting the bound Application
     * UUID for the "{application}" placeholder.
     *
     * @param  array<string, mixed>  $push
     */
    private function resolveChannel(array $push): string
    {
        $template = (string) ($push['channel'] ?? 'applications.{application}');
        $appUuid = (string) ($this->config['application_uuid'] ?? '');
        $name = str_replace('{application}', $appUuid, $template);

        return str_starts_with($name, 'private-') ? $name : 'private-'.$name;
    }

    /**
     * @param  array<string, mixed>  $push
     */
    private function socketUrl(array $push): string
    {
        $scheme = (string) ($push['scheme'] ?? 'https');
        $wsScheme = in_array($scheme, ['https', 'wss'], true) ? 'wss' : 'ws';

        $host = (string) ($push['host'] ?? '');
        if ($host === '') {
            $host = (string) (parse_url((string) ($this->config['url'] ?? ''), PHP_URL_HOST) ?: 'localhost');
        }

        $port = (int) ($push['port'] ?? 443);
        $key = (string) ($push['key'] ?? '');
        $version = (string) ($this->config['agent_version'] ?? '0.0.0');

        return sprintf(
            '%s://%s:%d/app/%s?protocol=7&client=platform-agent&version=%s&flash=false',
            $wsScheme,
            $host,
            $port,
            rawurlencode($key),
            rawurlencode($version),
        );
    }
}
