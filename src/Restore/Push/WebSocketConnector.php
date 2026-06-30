<?php

declare(strict_types=1);

namespace SidewalkDevelopers\PlatformAgent\Restore\Push;

/**
 * Minimal WebSocket transport seam used by {@see PusherRestoreSubscriber} (PA5).
 *
 * Abstracted so the Pusher-protocol subscriber is testable against a fake that
 * replays frames, with no real socket. The shipped {@see StreamWebSocketConnector}
 * speaks RFC 6455 over native PHP streams (no extra Composer dependency).
 */
interface WebSocketConnector
{
    /**
     * Open the connection + perform the WebSocket upgrade handshake. Throws on
     * connect/handshake failure.
     */
    public function connect(string $url, float $timeout): void;

    /**
     * Send a text frame (a Pusher protocol JSON message).
     */
    public function send(string $payload): void;

    /**
     * Block for the next application text payload, up to $timeout seconds.
     * Returns null on idle timeout or a clean close (the caller treats both as a
     * Rule-6 poll tick). Control frames (ping/pong/close) are handled internally.
     */
    public function receive(float $timeout): ?string;

    public function isConnected(): bool;

    public function close(): void;
}
