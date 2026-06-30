<?php

declare(strict_types=1);

namespace SidewalkDevelopers\PlatformAgent\Restore;

/**
 * Outcome of one {@see RestoreCoordinator} sweep (PA5): how many downloadable
 * restore jobs were considered, deposited (pull + Rule-4 verify + deposit +
 * report ok), and how many failed (manifest/download/checksum/deposit). A
 * discovery failure (could not list jobs) sets {@see $discoveryFailed} with the
 * reason. No failure is silent — each is reported to the Hub by the coordinator.
 */
final class RestoreSweepResult
{
    public function __construct(
        public readonly int $considered = 0,
        public readonly int $deposited = 0,
        public readonly int $failed = 0,
        public readonly bool $discoveryFailed = false,
        public readonly ?string $reason = null,
    ) {
    }

    public static function discoveryFailed(string $reason): self
    {
        return new self(discoveryFailed: true, reason: $reason);
    }

    public function didWork(): bool
    {
        return $this->deposited > 0 || $this->failed > 0;
    }
}
