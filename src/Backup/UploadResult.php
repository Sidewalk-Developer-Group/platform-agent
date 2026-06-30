<?php

declare(strict_types=1);

namespace SidewalkDevelopers\PlatformAgent\Backup;

/**
 * Result of uploading one archive to the Hub — via the single-POST `/agent/archives`
 * path or the resumable tus `/agent/uploads` path (PA3).
 *
 * `status` is the Hub's authoritative Rule-4 verdict (`verified` | `corrupted`);
 * `archiveId` is the catalog row id used to link the terminal `success`
 * backup-run. `via` records which transport was used (`single` | `tus`).
 */
final class UploadResult
{
    public function __construct(
        public readonly ?string $archiveId,
        public readonly string $status,
        public readonly string $via,
    ) {
    }

    public function isVerified(): bool
    {
        return $this->status === 'verified';
    }

    public function isCorrupted(): bool
    {
        return $this->status === 'corrupted';
    }
}
