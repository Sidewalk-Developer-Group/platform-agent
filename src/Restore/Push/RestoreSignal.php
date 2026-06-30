<?php

declare(strict_types=1);

namespace SidewalkDevelopers\PlatformAgent\Restore\Push;

/**
 * A discovery signal emitted by a {@see RestorePushSubscriber} (PA5).
 *
 * Two kinds, both resolved by the SAME drain → manifest → pull → SHA256 verify
 * (Rule 4) → deposit → report path — the signal only changes WHEN that runs:
 *
 *  - RESTORE: the Hub broadcast a restore event for this Application. `jobId`
 *    carries the restore job id when the payload included one (the Hub may also
 *    broadcast id-less "something changed, drain now" events — `jobId` is null
 *    then and the agent drains every downloadable job).
 *  - POLL: an idle-timeout / reconnect safety tick. Polling is the Rule-6
 *    fallback and is never removed; this guarantees a sweep even if a push was
 *    missed while disconnected.
 */
final class RestoreSignal
{
    private const RESTORE = 'restore';

    private const POLL = 'poll';

    private function __construct(
        public readonly string $type,
        public readonly ?string $jobId = null,
        public readonly ?string $reason = null,
    ) {
    }

    public static function restore(?string $jobId = null): self
    {
        return new self(self::RESTORE, $jobId);
    }

    /**
     * An idle / reconnect safety tick that triggers the Rule-6 poll fallback.
     */
    public static function poll(?string $reason = null): self
    {
        return new self(self::POLL, null, $reason);
    }

    public function isRestore(): bool
    {
        return $this->type === self::RESTORE;
    }

    public function isPoll(): bool
    {
        return $this->type === self::POLL;
    }
}
