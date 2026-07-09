<?php

declare(strict_types=1);

namespace SidewalkDevelopers\PlatformAgent\Console;

use SidewalkDevelopers\PlatformAgent\Backup\BackupCleaner;

/**
 * `platform-agent:clean --kind=database|files` — apply LOCAL retention (v1.1.0).
 *
 * Turns `backup.kinds.*.retention_days` live: spatie `backup:clean` scoped to
 * this kind's backup name + the local temp disk, with keep-all pinned to the
 * kind's retention horizon (see {@see \SidewalkDevelopers\PlatformAgent\Backup\SpatieBackupCleaner}
 * for the exact semantics). Purely LOCAL hygiene for orphaned temp archives —
 * the Hub/storage-node retention is governed by the platform, never from here.
 *
 * Scheduled daily per kind by {@see \SidewalkDevelopers\PlatformAgent\PlatformAgent::schedule()}
 * when `backup.clean_enabled` (default true). A manual invocation always runs —
 * the toggle gates the schedule only.
 */
final class CleanCommand extends AbstractAgentCommand
{
    private const KINDS = ['database', 'files'];

    private const SUFFIX = ['database' => 'db', 'files' => 'files'];

    protected $signature = 'platform-agent:clean
        {--kind=database : Which split backup kind to clean: database or files}';

    protected $description = 'Delete local backup archives older than the configured retention (per kind).';

    protected string $implementedInPhase = 'v1.1.0';

    public function handle(BackupCleaner $cleaner): int
    {
        $kind = (string) $this->option('kind');
        if (! in_array($kind, self::KINDS, true)) {
            $this->components->error('Invalid --kind "'.$kind.'". Use one of: '.implode(', ', self::KINDS).'.');

            return self::FAILURE;
        }

        $retentionDays = (int) config('platform-agent.backup.kinds.'.$kind.'.retention_days', 0);

        if ($retentionDays <= 0) {
            $this->components->info('Local retention is disabled for the '.$kind.' kind (retention_days <= 0) — nothing cleaned.');

            return self::SUCCESS;
        }

        $spatieName = $this->spatieName($kind);

        $error = $cleaner->clean($kind, $spatieName, $this->tempDisk(), $retentionDays);

        if ($error !== null) {
            $this->components->error(ucfirst($kind).' retention clean failed: '.$error);

            return self::FAILURE;
        }

        $this->components->info(
            ucfirst($kind).' local retention applied ('.$spatieName.', keep '.$retentionDays.' days).',
        );

        return self::SUCCESS;
    }

    private function spatieName(string $kind): string
    {
        $configured = config('platform-agent.backup.kinds.'.$kind.'.spatie_name');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return ((string) config('platform-agent.backup.name', 'platform-agent')).'-'.self::SUFFIX[$kind];
    }

    private function tempDisk(): string
    {
        return (string) config('platform-agent.backup.temp_disk', 'local');
    }
}
