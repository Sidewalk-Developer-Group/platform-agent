<?php

declare(strict_types=1);

namespace SidewalkDevelopers\PlatformAgent\Backup;

use Illuminate\Contracts\Console\Kernel as Artisan;

/**
 * Concrete {@see BackupCleaner} over spatie `backup:clean` (v1.1.0) — the same
 * thin-glue pattern as {@see SpatieBackupRunner}.
 *
 * Scoping mirrors a backup run exactly: the clean is pinned to THIS kind's
 * spatie backup name and the LOCAL temp disk, so it only ever touches the
 * agent's own archives (never the customer's separate spatie backups). The
 * per-run temp hygiene already deletes each uploaded archive; this clean is the
 * safety net for ORPHANS a crashed/interrupted run left behind.
 *
 * Retention semantics: `backup.kinds.*.retention_days` is authoritative —
 * everything is kept for N days, then deleted. The graduated spatie tiers
 * (daily/weekly/monthly/yearly) are zeroed for the run so nothing outlives the
 * horizon; the customer's `delete_oldest_when_using_more_megabytes_than` disk
 * cap is left untouched (it only ever deletes MORE, protecting the disk). Runs
 * with `--disable-notifications` — the agent never fires the customer's
 * configured backup mail/Slack.
 */
final class SpatieBackupCleaner implements BackupCleaner
{
    public function __construct(
        private readonly Artisan $artisan,
        private readonly \Illuminate\Contracts\Config\Repository $config,
    ) {
    }

    public function clean(string $kind, string $spatieName, string $tempDisk, int $retentionDays): ?string
    {
        // Scope spatie to this kind's name + the local temp disk only — read at
        // run time by backup:clean; the override is never persisted.
        $this->config->set('backup.backup.name', $spatieName);
        $this->config->set('backup.backup.destination.disks', [$tempDisk]);

        // retention_days governs alone: keep everything N days, then delete.
        $this->config->set('backup.cleanup.default_strategy.keep_all_backups_for_days', $retentionDays);
        $this->config->set('backup.cleanup.default_strategy.keep_daily_backups_for_days', 0);
        $this->config->set('backup.cleanup.default_strategy.keep_weekly_backups_for_weeks', 0);
        $this->config->set('backup.cleanup.default_strategy.keep_monthly_backups_for_months', 0);
        $this->config->set('backup.cleanup.default_strategy.keep_yearly_backups_for_years', 0);

        try {
            $exitCode = $this->artisan->call('backup:clean', [
                '--disable-notifications' => true,
            ]);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        if ($exitCode !== 0) {
            $output = trim($this->artisan->output());

            return $output !== '' ? $output : 'spatie backup:clean returned a non-zero exit code.';
        }

        return null;
    }
}
