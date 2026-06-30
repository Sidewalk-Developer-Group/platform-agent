<?php

declare(strict_types=1);

use SidewalkDevelopers\PlatformAgent\Restore\Push\PusherFrame;

/*
|--------------------------------------------------------------------------
| PusherFrame — pure RFC 6455 client framing (PA5)
|--------------------------------------------------------------------------
*/

it('encodes a masked client text frame with FIN set', function () {
    $frame = PusherFrame::encode('hi', PusherFrame::OP_TEXT);

    $first = ord($frame[0]);
    $second = ord($frame[1]);

    expect($first & 0x80)->toBe(0x80)          // FIN
        ->and($first & 0x0F)->toBe(PusherFrame::OP_TEXT)
        ->and($second & 0x80)->toBe(0x80)      // client frames MUST be masked
        ->and($second & 0x7F)->toBe(2);        // payload length
});

it('round-trips a payload through encode → decode', function () {
    $payload = json_encode(['event' => 'restore.requested', 'data' => 'x']);
    $frame = PusherFrame::encode($payload);

    $consumed = 0;
    $decoded = PusherFrame::decode($frame, $consumed);

    expect($decoded)->not->toBeNull()
        ->and($decoded['opcode'])->toBe(PusherFrame::OP_TEXT)
        ->and($decoded['fin'])->toBeTrue()
        ->and($decoded['payload'])->toBe($payload)
        ->and($consumed)->toBe(strlen($frame));
});

it('decodes an unmasked server frame', function () {
    // FIN+text, len 5, "hello" — exactly what Reverb sends (servers never mask).
    $frame = "\x81\x05hello";

    $consumed = 0;
    $decoded = PusherFrame::decode($frame, $consumed);

    expect($decoded['payload'])->toBe('hello')
        ->and($decoded['opcode'])->toBe(PusherFrame::OP_TEXT)
        ->and($consumed)->toBe(7);
});

it('handles the 16-bit extended length boundary', function () {
    $payload = str_repeat('a', 200); // > 125 → 126 + 16-bit length
    $frame = PusherFrame::encode($payload);

    expect(ord($frame[1]) & 0x7F)->toBe(126);

    $consumed = 0;
    $decoded = PusherFrame::decode($frame, $consumed);

    expect($decoded['payload'])->toBe($payload);
});

it('returns null when the buffer holds an incomplete frame', function () {
    $full = PusherFrame::encode(str_repeat('z', 300));
    $partial = substr($full, 0, 5); // header + a few bytes only

    $consumed = 0;
    expect(PusherFrame::decode($partial, $consumed))->toBeNull();
});

it('decodes only the first frame and reports bytes consumed', function () {
    $a = PusherFrame::encode('one');
    $b = PusherFrame::encode('two');

    $consumed = 0;
    $first = PusherFrame::decode($a.$b, $consumed);

    expect($first['payload'])->toBe('one')
        ->and($consumed)->toBe(strlen($a));

    // The remaining buffer still decodes the second frame.
    $rest = substr($a.$b, $consumed);
    $consumed2 = 0;
    expect(PusherFrame::decode($rest, $consumed2)['payload'])->toBe('two');
});
