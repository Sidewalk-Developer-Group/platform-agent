<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use SidewalkDevelopers\PlatformAgent\State\AgentStateStore;

function stateStore(): AgentStateStore
{
    return app(AgentStateStore::class);
}

it('round-trips a state value', function () {
    stateStore()->put('probe', ['answer' => 42]);

    expect(stateStore()->get('probe'))->toBe(['answer' => 42])
        ->and(stateStore()->isReady())->toBeTrue();
});

it('returns null for an absent key', function () {
    expect(stateStore()->get('never-written'))->toBeNull();
});

it('records a backup run outcome per kind and keeps last_success_at across a later failure', function () {
    $state = stateStore();

    $state->recordBackupRun('database', success: true, finishedAt: new DateTimeImmutable('2026-07-09 03:00:00 +00:00'));
    expect($state->failedBackupKinds())->toBe([])
        ->and($state->lastSuccessfulBackupAt()?->toIso8601String())->toBe('2026-07-09T03:00:00+00:00');

    // A failure flips the status but must NOT erase the last success time.
    $state->recordBackupRun('database', success: false, finishedAt: new DateTimeImmutable('2026-07-10 03:00:00 +00:00'));
    expect($state->failedBackupKinds())->toBe(['database'])
        ->and($state->lastSuccessfulBackupAt()?->toIso8601String())->toBe('2026-07-09T03:00:00+00:00');
});

it('reports the most recent success across kinds as last_backup_at', function () {
    $state = stateStore();

    $state->recordBackupRun('database', success: true, finishedAt: new DateTimeImmutable('2026-07-08 06:00:00 +00:00'));
    $state->recordBackupRun('files', success: true, finishedAt: new DateTimeImmutable('2026-07-10 02:00:00 +00:00'));

    expect($state->lastSuccessfulBackupAt()?->toIso8601String())->toBe('2026-07-10T02:00:00+00:00');
});

it('records and reads the scheduled-heartbeat freshness marker', function () {
    $state = stateStore();

    expect($state->lastScheduledHeartbeatAt())->toBeNull();

    $state->recordScheduledHeartbeat(new DateTimeImmutable('2026-07-10 08:05:00 +00:00'));

    expect($state->lastScheduledHeartbeatAt()?->toIso8601String())->toBe('2026-07-10T08:05:00+00:00');
});

it('is null-safe when the state table is missing (upgrade before migrate)', function () {
    Schema::dropIfExists('platform_agent_state');

    $state = stateStore();

    expect($state->isReady())->toBeFalse()
        ->and($state->get('anything'))->toBeNull()
        ->and($state->lastSuccessfulBackupAt())->toBeNull()
        ->and($state->failedBackupKinds())->toBe([]);

    // Writes must swallow, never break a backup or heartbeat.
    $state->put('anything', ['x' => 1]);
    $state->recordBackupRun('database', success: true);

    expect($state->get('anything'))->toBeNull();
});
