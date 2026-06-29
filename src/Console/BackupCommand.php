<?php

declare(strict_types=1);

namespace SidewalkDevelopers\PlatformAgent\Console;

/**
 * `platform-agent:backup --kind=database|files` — split backup (PA3).
 *
 * Backups are SPLIT per kind (ADR-0007 Addendum F): each invocation dispatches a
 * single kind via spatie (`backup:run --only-db` / `--only-files`) to a LOCAL
 * temp disk, generates the SHA256 sidecar (Rule 4), and uploads the pair
 * (single-POST /agent/archives for small, tus /agent/uploads for large by
 * threshold_bytes). DB and files are NEVER combined. "Back up everything" =
 * run both kinds.
 */
final class BackupCommand extends AbstractAgentCommand
{
    protected $signature = 'platform-agent:backup
        {--kind=database : Which split backup to run: database or files}';

    protected $description = 'Run a split (database|files) backup, checksum and upload it to the Cloud Hub.';

    protected string $implementedInPhase = 'PA3';

    public function handle(): int
    {
        return $this->notImplemented();
    }
}
