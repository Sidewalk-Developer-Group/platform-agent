<?php

declare(strict_types=1);

namespace SidewalkDevelopers\PlatformAgent\Restore\Push;

/**
 * Pure RFC 6455 frame helpers for the native-stream WebSocket connector (PA5).
 *
 * Kept dependency-free and side-effect-free so the framing — the only fiddly,
 * bug-prone part of a hand-rolled client — is unit-testable in isolation, while
 * {@see StreamWebSocketConnector} owns only the socket I/O around it.
 *
 * Only the client subset is implemented: client→server frames are always masked
 * (RFC 6455 §5.3); server→client frames are never masked. Text + the control
 * opcodes (ping/pong/close) are supported; continuation/fragmentation is not
 * used by the Pusher protocol.
 */
final class PusherFrame
{
    public const OP_CONTINUATION = 0x0;

    public const OP_TEXT = 0x1;

    public const OP_BINARY = 0x2;

    public const OP_CLOSE = 0x8;

    public const OP_PING = 0x9;

    public const OP_PONG = 0xA;

    /**
     * Encode a single masked client frame (FIN set). $opcode defaults to text.
     */
    public static function encode(string $payload, int $opcode = self::OP_TEXT): string
    {
        $frame = chr(0x80 | ($opcode & 0x0F));

        $length = strlen($payload);
        $mask = 0x80; // client frames MUST be masked.

        if ($length <= 125) {
            $frame .= chr($mask | $length);
        } elseif ($length <= 0xFFFF) {
            $frame .= chr($mask | 126).pack('n', $length);
        } else {
            // 64-bit length, high word zero (J = unsigned long long, big-endian).
            $frame .= chr($mask | 127).pack('J', $length);
        }

        $maskingKey = random_bytes(4);
        $frame .= $maskingKey;
        $frame .= self::applyMask($payload, $maskingKey);

        return $frame;
    }

    /**
     * Decode the first complete frame in $buffer.
     *
     * Returns ['fin' => bool, 'opcode' => int, 'payload' => string] and sets
     * $consumed to the number of bytes the frame occupied, or null when the
     * buffer does not yet hold a complete frame (the caller should read more).
     *
     * @param  int  $consumed  out-param: bytes consumed when a frame is returned
     * @return array{fin: bool, opcode: int, payload: string}|null
     */
    public static function decode(string $buffer, int &$consumed): ?array
    {
        $len = strlen($buffer);
        if ($len < 2) {
            return null;
        }

        $first = ord($buffer[0]);
        $second = ord($buffer[1]);

        $fin = (bool) ($first & 0x80);
        $opcode = $first & 0x0F;
        $masked = (bool) ($second & 0x80);
        $payloadLen = $second & 0x7F;

        $offset = 2;

        if ($payloadLen === 126) {
            if ($len < $offset + 2) {
                return null;
            }
            $payloadLen = unpack('n', substr($buffer, $offset, 2))[1];
            $offset += 2;
        } elseif ($payloadLen === 127) {
            if ($len < $offset + 8) {
                return null;
            }
            $payloadLen = unpack('J', substr($buffer, $offset, 8))[1];
            $offset += 8;
        }

        if ($masked) {
            // Servers MUST NOT mask, but tolerate it for robustness.
            if ($len < $offset + 4) {
                return null;
            }
            $maskingKey = substr($buffer, $offset, 4);
            $offset += 4;
        }

        if ($len < $offset + $payloadLen) {
            return null;
        }

        $payload = substr($buffer, $offset, $payloadLen);

        if ($masked) {
            $payload = self::applyMask($payload, $maskingKey);
        }

        $consumed = $offset + $payloadLen;

        return ['fin' => $fin, 'opcode' => $opcode, 'payload' => $payload];
    }

    private static function applyMask(string $payload, string $maskingKey): string
    {
        $masked = '';
        $keyLen = strlen($maskingKey);

        for ($i = 0, $n = strlen($payload); $i < $n; $i++) {
            $masked .= $payload[$i] ^ $maskingKey[$i % $keyLen];
        }

        return $masked;
    }
}
