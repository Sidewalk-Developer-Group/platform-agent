<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use SidewalkDevelopers\PlatformAgent\State\AgentStateStore;
use SidewalkDevelopers\PlatformAgent\Telemetry\TelemetryCollector;

function telemetry(): TelemetryCollector
{
    return app(TelemetryCollector::class);
}

/**
 * A throwaway measured tree: {8 bytes} + sub/{4 bytes} = 12 bytes.
 */
function fakeStorageTree(): string
{
    $dir = sys_get_temp_dir().'/pa-telemetry-'.uniqid();
    mkdir($dir.'/sub', 0755, true);
    file_put_contents($dir.'/a.log', '12345678');
    file_put_contents($dir.'/sub/b.log', 'abcd');

    config()->set('platform-agent.telemetry.storage_paths', [$dir]);

    return $dir;
}

it('measures storage usage recursively across the configured paths', function () {
    fakeStorageTree();

    expect(telemetry()->storageUsageBytes())->toBe(12);
});

it('caches the measurement so the 5-minute heartbeat stays cheap', function () {
    $dir = fakeStorageTree();

    expect(telemetry()->storageUsageBytes())->toBe(12);

    // Growth within the TTL is not re-measured…
    file_put_contents($dir.'/c.log', 'xx');
    expect(telemetry()->storageUsageBytes())->toBe(12);

    // …until the cache expires (flush simulates expiry).
    Cache::flush();
    expect(telemetry()->storageUsageBytes())->toBe(14);
});

it('measures live when the cache TTL is 0 (disabled)', function () {
    $dir = fakeStorageTree();
    config()->set('platform-agent.telemetry.cache_ttl_seconds', 0);

    expect(telemetry()->storageUsageBytes())->toBe(12);

    file_put_contents($dir.'/c.log', 'xx');
    expect(telemetry()->storageUsageBytes())->toBe(14);
});

it('skips unmeasurable paths and sums the rest', function () {
    $dir = fakeStorageTree();
    config()->set('platform-agent.telemetry.storage_paths', [$dir, '/nonexistent/path/'.uniqid()]);
    config()->set('platform-agent.telemetry.cache_ttl_seconds', 0);

    expect(telemetry()->storageUsageBytes())->toBe(12);
});

it('reports null — never a fabricated 0 — when nothing is measurable', function () {
    config()->set('platform-agent.telemetry.storage_paths', ['/nonexistent/path/'.uniqid()]);

    expect(telemetry()->storageUsageBytes())->toBeNull()
        ->and(telemetry()->extra())->not->toHaveKey('storage_usage_bytes');
});

it('exposes last_backup_at from the latest successful local run', function () {
    fakeStorageTree();
    app(AgentStateStore::class)->recordBackupRun('files', success: true, finishedAt: new DateTimeImmutable('2026-07-10 02:00:00 +00:00'));

    $extra = telemetry()->extra();

    expect($extra['last_backup_at'])->toBe('2026-07-10T02:00:00+00:00')
        ->and($extra['storage_usage_bytes'])->toBe(12);
});

it('computes healthy by default and never emits a usage percentage', function () {
    fakeStorageTree();

    expect(telemetry()->status())->toBe('healthy')
        ->and(telemetry()->degradedReasons())->toBe([])
        ->and(telemetry()->extra())->not->toHaveKey('usage_percentage')
        ->and(telemetry()->metadata())->not->toHaveKey('usage_percentage');
});

it('degrades when the most recent run of a backup kind failed', function () {
    app(AgentStateStore::class)->recordBackupRun('database', success: false);

    expect(telemetry()->status())->toBe('degraded')
        ->and(telemetry()->degradedReasons())->toBe(['last_database_backup_failed'])
        ->and(telemetry()->metadata()['status_reasons'])->toBe(['last_database_backup_failed']);
});

it('degrades when free disk drops below the configured floor', function () {
    config()->set('platform-agent.telemetry.min_free_bytes', PHP_INT_MAX);

    expect(telemetry()->status())->toBe('degraded')
        ->and(telemetry()->degradedReasons())->toContain('low_disk_free');
});

it('leaves the disk floor disabled by default (min_free_bytes = 0)', function () {
    expect((int) config('platform-agent.telemetry.min_free_bytes'))->toBe(0)
        ->and(telemetry()->status())->toBe('healthy');
});

it('reports base-path disk facts in metadata (bytes only)', function () {
    $metadata = telemetry()->metadata();

    expect($metadata['disk_free_bytes'])->toBeInt()->toBeGreaterThan(0)
        ->and($metadata['disk_total_bytes'])->toBeInt()->toBeGreaterThan(0)
        ->and($metadata['disk_total_bytes'])->toBeGreaterThanOrEqual($metadata['disk_free_bytes']);
});
