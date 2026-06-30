<?php

declare(strict_types=1);

use SidewalkDevelopers\PlatformAgent\Reporting\BackupRunReporter;

it('redacts secrets from a failure message', function (string $in, array $mustNotContain, string $mustContain) {
    $out = BackupRunReporter::sanitize($in);

    foreach ($mustNotContain as $leak) {
        expect($out)->not->toContain($leak);
    }
    expect($out)->toContain($mustContain);
})->with([
    'password pair' => ['Dump failed: password=topsecret here', ['topsecret'], '[redacted]'],
    'bearer token' => ['Auth: Bearer abc123def456 rejected', ['abc123def456'], '[redacted]'],
    'dsn creds' => ['mysql://root:hunter2@db:3306/app', ['hunter2'], '[redacted]'],
]);

it('truncates an over-long message', function () {
    $out = BackupRunReporter::sanitize(str_repeat('x', 5000), 100);

    expect(mb_strlen($out))->toBeLessThanOrEqual(101); // 100 + ellipsis
    expect($out)->toEndWith('…');
});
