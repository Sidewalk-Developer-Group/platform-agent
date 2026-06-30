<?php

declare(strict_types=1);

namespace SidewalkDevelopers\PlatformAgent\Backup;

/**
 * Runs ONE split backup kind via spatie and returns where the zip landed (PA3).
 *
 * A seam (not concrete) so the orchestration in {@see \SidewalkDevelopers\PlatformAgent\Console\BackupCommand}
 * is testable without invoking the real `backup:run` — tests bind a fake that
 * writes a known temp file. The shipped binding is {@see SpatieBackupRunner}.
 */
interface BackupRunner
{
    /**
     * Run the given kind (`database` => `--only-db`, `files` => `--only-files`)
     * under the given spatie backup name, writing to the local temp disk only.
     *
     * @param  'database'|'files'  $kind
     */
    public function run(string $kind, string $spatieName, string $tempDisk): BackupResult;
}
