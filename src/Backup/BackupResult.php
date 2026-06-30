<?php

declare(strict_types=1);

namespace SidewalkDevelopers\PlatformAgent\Backup;

/**
 * Outcome of a single split spatie backup run (one kind) (PA3).
 *
 * Carries the absolute local path to the produced `backup.zip` (temp disk only —
 * NEVER a node or the Hub; ADR-0007 §7.2) and its size on success, or a sanitized
 * error string on failure. Immutable.
 */
final class BackupResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $archivePath,
        public readonly int $sizeBytes,
        public readonly string $spatieName,
        public readonly ?string $error,
    ) {
    }

    public static function success(string $archivePath, int $sizeBytes, string $spatieName): self
    {
        return new self(true, $archivePath, $sizeBytes, $spatieName, null);
    }

    public static function failed(string $error, string $spatieName): self
    {
        return new self(false, null, 0, $spatieName, $error);
    }
}
