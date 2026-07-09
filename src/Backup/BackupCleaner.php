<?php

declare(strict_types=1);

namespace SidewalkDevelopers\PlatformAgent\Backup;

/**
 * Seam for applying local retention to ONE split backup kind (v1.1.0) — the
 * cleanup sibling of {@see BackupRunner}, faked the same way in command tests.
 */
interface BackupCleaner
{
    /**
     * Delete this kind's local archives older than the retention horizon.
     *
     * @param  'database'|'files'  $kind
     * @param  string  $spatieName  the kind's spatie backup name (scopes the clean)
     * @param  string  $tempDisk    the LOCAL temp disk backups are written to
     * @param  int  $retentionDays  keep-everything horizon in days (> 0)
     * @return string|null an error message, or null on success
     */
    public function clean(string $kind, string $spatieName, string $tempDisk, int $retentionDays): ?string;
}
