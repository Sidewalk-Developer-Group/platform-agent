<?php

declare(strict_types=1);

use SidewalkDevelopers\PlatformAgent\Reporting\EnvironmentReporter;

function reporter(): EnvironmentReporter
{
    return app(EnvironmentReporter::class);
}

it('builds a register payload with version, host and a sha256 fingerprint, no application_id', function () {
    $payload = reporter()->registerPayload();

    expect($payload)->toHaveKeys(['agent_version', 'hostname', 'fingerprint', 'metadata'])
        ->and($payload)->not->toHaveKey('application_id')
        ->and($payload['agent_version'])->toBe('1.4.0')
        ->and($payload['fingerprint'])->toStartWith('sha256:')
        ->and($payload['metadata'])->toHaveKeys(['os', 'php']);
});

it('derives a stable fingerprint across calls (same pairing key)', function () {
    expect(reporter()->fingerprint())->toBe(reporter()->fingerprint());
});

it('builds a bytes-only telemetry snapshot — never a usage percentage', function () {
    $snapshot = reporter()->snapshot('healthy', ['queue' => 'ok']);

    expect($snapshot)->toHaveKeys(['agent_version', 'php_version', 'framework_version', 'status', 'metadata', 'recorded_at'])
        ->and($snapshot)->not->toHaveKey('usage_percentage')
        ->and($snapshot)->not->toHaveKey('storage_usage_percentage')
        ->and($snapshot['status'])->toBe('healthy')
        ->and($snapshot['framework_version'])->toStartWith('Laravel ')
        ->and($snapshot['metadata'])->toBe(['queue' => 'ok'])
        // recorded_at is an ISO-8601 / ATOM timestamp.
        ->and(strtotime($snapshot['recorded_at']))->not->toBeFalse();
});

it('merges known wire fields (e.g. storage_usage_bytes) via $extra without inventing them', function () {
    $snapshot = reporter()->snapshot('healthy', [], ['storage_usage_bytes' => 5368709120]);

    expect($snapshot['storage_usage_bytes'])->toBe(5368709120);

    // Default snapshot (no $extra) omits it — values come from the backup subsystem (PA3).
    expect(reporter()->snapshot('healthy'))->not->toHaveKey('storage_usage_bytes');
});
