<?php

declare(strict_types=1);

namespace SidewalkDevelopers\PlatformAgent\Backup;

use Illuminate\Contracts\Console\Kernel as Artisan;
use Illuminate\Contracts\Events\Dispatcher;
use Spatie\Backup\Events\BackupZipWasCreated;

/**
 * Concrete {@see BackupRunner} over `spatie/laravel-backup` v10 (ADR-0007
 * Addendum A) — thin glue, the same "real I/O behind a faked seam" pattern as the
 * live HTTP in PlatformClient (PA3).
 *
 * Split per kind (ADR-0007 Addendum F): `database` => `--only-db`,
 * `files` => `--only-files` — the two are NEVER combined. The run is scoped to a
 * per-kind spatie backup name and pinned to the LOCAL temp disk only; the exact
 * produced path is captured from the `BackupZipWasCreated` event rather than
 * guessed from a filename (the Hub never parses filenames — `kind` is
 * authoritative).
 */
final class SpatieBackupRunner implements BackupRunner
{
    public function __construct(
        private readonly Artisan $artisan,
        private readonly Dispatcher $events,
        private readonly \Illuminate\Contracts\Config\Repository $config,
    ) {
    }

    public function run(string $kind, string $spatieName, string $tempDisk): BackupResult
    {
        // Scope spatie to this kind's name + the local temp disk only. spatie reads
        // these at run time; we never persist the override.
        $this->config->set('backup.backup.name', $spatieName);
        $this->config->set('backup.backup.destination.disks', [$tempDisk]);

        // Capture the exact produced path from the event (authoritative over any
        // filename guess). The listener lives only for this short-lived CLI
        // process, so it is intentionally not unregistered (forget() would also
        // drop the customer's own listeners for this event).
        $capturedPath = null;
        $this->events->listen(
            BackupZipWasCreated::class,
            function (BackupZipWasCreated $event) use (&$capturedPath): void {
                $capturedPath = $event->pathToZip;
            },
        );

        try {
            $exitCode = $this->artisan->call('backup:run', [
                $kind === 'database' ? '--only-db' : '--only-files' => true,
                '--disable-notifications' => true,
            ]);
        } catch (\Throwable $e) {
            return BackupResult::failed($e->getMessage(), $spatieName);
        }

        $output = trim($this->artisan->output());

        if ($exitCode !== 0 || $capturedPath === null || ! is_file($capturedPath)) {
            return BackupResult::failed(
                $output !== '' ? $output : 'spatie backup:run did not produce an archive.',
                $spatieName,
            );
        }

        return BackupResult::success($capturedPath, (int) filesize($capturedPath), $spatieName);
    }
}
