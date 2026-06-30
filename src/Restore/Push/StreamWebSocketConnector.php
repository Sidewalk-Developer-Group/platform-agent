<?php

declare(strict_types=1);

namespace SidewalkDevelopers\PlatformAgent\Restore\Push;

use RuntimeException;

/**
 * RFC 6455 WebSocket client over native PHP streams (PA5).
 *
 * Dependency-free so the customer-installed agent pulls in no WebSocket library
 * just for the restore-push latency optimization. It performs the HTTP upgrade
 * handshake, then frames/deframes via the pure {@see PusherFrame} helpers.
 * Control frames are handled here: a server ping is answered with a pong, and a
 * close frame ends the stream (the subscriber falls back to polling).
 *
 * Secrets are never logged; this layer only moves bytes.
 */
final class StreamWebSocketConnector implements WebSocketConnector
{
    /** @var resource|null */
    private $socket = null;

    private string $buffer = '';

    public function connect(string $url, float $timeout): void
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['host'])) {
            throw new RuntimeException('Invalid WebSocket URL: '.$url);
        }

        $scheme = ($parts['scheme'] ?? 'ws');
        $secure = in_array($scheme, ['wss', 'https'], true);
        $host = $parts['host'];
        $port = (int) ($parts['port'] ?? ($secure ? 443 : 80));
        $path = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');

        $transport = $secure ? 'ssl' : 'tcp';
        $remote = sprintf('%s://%s:%d', $transport, $host, $port);

        $context = stream_context_create([
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true],
        ]);

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            $remote,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($socket === false) {
            throw new RuntimeException(sprintf('WebSocket connect to %s failed: %s (%d)', $remote, $errstr, $errno));
        }

        stream_set_timeout($socket, (int) $timeout);
        $this->socket = $socket;

        $this->handshake($host, $port, $path, $secure);
    }

    private function handshake(string $host, int $port, string $path, bool $secure): void
    {
        $key = base64_encode(random_bytes(16));
        $hostHeader = $host.(($secure && $port === 443) || (! $secure && $port === 80) ? '' : ':'.$port);

        $request = "GET {$path} HTTP/1.1\r\n"
            ."Host: {$hostHeader}\r\n"
            ."Upgrade: websocket\r\n"
            ."Connection: Upgrade\r\n"
            ."Sec-WebSocket-Key: {$key}\r\n"
            ."Sec-WebSocket-Version: 13\r\n"
            ."\r\n";

        $this->writeRaw($request);

        $response = $this->readHttpResponseHead();

        if (! preg_match('#^HTTP/1\.1 101#', $response)) {
            $statusLine = strtok($response, "\r\n");
            $this->close();

            throw new RuntimeException('WebSocket upgrade rejected: '.$statusLine);
        }

        $expected = base64_encode(sha1($key.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        if (stripos($response, 'Sec-WebSocket-Accept: '.$expected) === false) {
            $this->close();

            throw new RuntimeException('WebSocket handshake accept key mismatch.');
        }
    }

    public function send(string $payload): void
    {
        $this->writeRaw(PusherFrame::encode($payload, PusherFrame::OP_TEXT));
    }

    public function receive(float $timeout): ?string
    {
        $deadline = microtime(true) + $timeout;

        while (true) {
            // Drain any complete frame already buffered before reading more.
            $consumed = 0;
            $frame = PusherFrame::decode($this->buffer, $consumed);

            if ($frame !== null) {
                $this->buffer = substr($this->buffer, $consumed);

                switch ($frame['opcode']) {
                    case PusherFrame::OP_TEXT:
                        return $frame['payload'];
                    case PusherFrame::OP_PING:
                        $this->writeRaw(PusherFrame::encode($frame['payload'], PusherFrame::OP_PONG));
                        continue 2;
                    case PusherFrame::OP_PONG:
                        continue 2;
                    case PusherFrame::OP_CLOSE:
                        $this->close();

                        return null;
                    default:
                        continue 2; // ignore binary/continuation — Pusher is text.
                }
            }

            $remaining = $deadline - microtime(true);
            if ($remaining <= 0 || ! $this->isConnected()) {
                return null;
            }

            $chunk = $this->readRaw($remaining);
            if ($chunk === null) {
                return null; // closed / errored — caller falls back to poll.
            }

            $this->buffer .= $chunk;
        }
    }

    public function isConnected(): bool
    {
        return is_resource($this->socket) && ! feof($this->socket);
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            @fwrite($this->socket, PusherFrame::encode('', PusherFrame::OP_CLOSE));
            @fclose($this->socket);
        }

        $this->socket = null;
        $this->buffer = '';
    }

    private function writeRaw(string $bytes): void
    {
        if (! is_resource($this->socket)) {
            throw new RuntimeException('WebSocket is not connected.');
        }

        $total = strlen($bytes);
        $written = 0;

        while ($written < $total) {
            $n = @fwrite($this->socket, substr($bytes, $written));
            if ($n === false || $n === 0) {
                throw new RuntimeException('WebSocket write failed.');
            }
            $written += $n;
        }
    }

    /**
     * Read up to $timeout seconds of bytes from the socket. Returns null on a
     * clean close or error; an empty string on a benign read timeout.
     */
    private function readRaw(float $timeout): ?string
    {
        if (! is_resource($this->socket)) {
            return null;
        }

        $sec = (int) max(0, floor($timeout));
        $usec = (int) (($timeout - $sec) * 1_000_000);
        $read = [$this->socket];
        $write = null;
        $except = null;

        $ready = @stream_select($read, $write, $except, $sec, $usec);
        if ($ready === false) {
            return null;
        }
        if ($ready === 0) {
            return ''; // timed out with no data — let the deadline loop decide.
        }

        $chunk = @fread($this->socket, 8192);
        if ($chunk === false || ($chunk === '' && feof($this->socket))) {
            return null;
        }

        return $chunk;
    }

    private function readHttpResponseHead(): string
    {
        $response = '';

        while (is_resource($this->socket) && ! feof($this->socket)) {
            $line = @fgets($this->socket, 8192);
            if ($line === false) {
                break;
            }
            $response .= $line;
            if (str_ends_with($response, "\r\n\r\n")) {
                break;
            }
        }

        return $response;
    }
}
