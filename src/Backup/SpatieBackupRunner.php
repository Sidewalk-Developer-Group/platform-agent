<?php

declare(strict_types=1);

namespace SidewalkDevelopers\PlatformAgent\Backup;

use Illuminate\Contracts\Console\Kernel as Artisan;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Concrete {@see BackupRunner} over `spatie/laravel-backup` v9 or v10 (ADR-0007
 * Addendum A) — thin glue, the same "real I/O behind a faked seam" pattern as the
 * live HTTP in PlatformClient (PA3).
 *
 * Split per kind (ADR-0007 Addendum F): `database` => `--only-db`,
 * `files` => `--only-files` — the two are NEVER combined. The run is scoped to a
 * per-kind spatie backup name and pinned to the LOCAL temp disk only.
 *
 * Locating the produced archive: we run spatie with `--disable-notifications`
 * (the agent must NEVER fire the customer's configured backup mail/Slack), which
 * ALSO suppresses spatie's `BackupWasSuccessful` / `BackupZipWasCreated` events
 * (both are dispatched through `sendNotification()`, gated on the same flag). So
 * we do not rely on those events at all — instead we diff the destination disk
 * for the `.zip` that appeared during this run. This is agnostic to the spatie
 * major (whose event shapes differ) and to the resolved backup-name directory.
 */
final class SpatieBackupRunner implements BackupRunner
{
    public function __construct(
        private readonly Artisan $artisan,
        private readonly \Illuminate\Contracts\Config\Repository $config,
    ) {
    }

    public function run(string $kind, string $spatieName, string $tempDisk): BackupResult
    {
        // Scope spatie to this kind's name + the local temp disk only. spatie reads
        // these at run time; we never persist the override.
        $this->config->set('backup.backup.name', $spatieName);
        $this->config->set('backup.backup.destination.disks', [$tempDisk]);

        $disk = Storage::disk($tempDisk);

        // Snapshot existing archives so we can isolate the one THIS run produces
        // (path => mtime — a re-run that overwrites a same-named zip still counts
        // as changed).
        $before = $this->zipMtimes($disk);

        try {
            $exitCode = $this->artisan->call('backup:run', [
                $kind === 'database' ? '--only-db' : '--only-files' => true,
                '--disable-notifications' => true,
            ]);
        } catch (\Throwable $e) {
            return BackupResult::failed($e->getMessage(), $spatieName);
        }

        $output = trim($this->artisan->output());

        if ($exitCode !== 0) {
            return BackupResult::failed(
                $output !== '' ? $output : 'spatie backup:run returned a non-zero exit code.',
                $spatieName,
            );
        }

        $produced = $this->newestNewZip($disk, $before);

        if ($produced === null) {
            return BackupResult::failed(
                $output !== '' ? $output : 'spatie backup:run did not produce an archive.',
                $spatieName,
            );
        }

        $absolute = $disk->path($produced);

        return BackupResult::success($absolute, (int) filesize($absolute), $spatieName);
    }

    /**
     * The relative path of the newest `.zip` on the disk that is new or changed
     * versus the pre-run snapshot, or null when nothing was produced.
     *
     * @param  array<string, int>  $before  relative zip path => mtime
     */
    private function newestNewZip(Filesystem $disk, array $before): ?string
    {
        $newest = null;
        $newestMtime = -1;

        foreach ($this->zipMtimes($disk) as $path => $mtime) {
            $changed = ! array_key_exists($path, $before) || $before[$path] !== $mtime;

            if ($changed && $mtime >= $newestMtime) {
                $newestMtime = $mtime;
                $newest = $path;
            }
        }

        return $newest;
    }

    /**
     * @return array<string, int> relative `.zip` path => last-modified epoch
     */
    private function zipMtimes(Filesystem $disk): array
    {
        $out = [];

        foreach ($disk->allFiles() as $file) {
            if (str_ends_with(strtolower($file), '.zip')) {
                $out[$file] = $disk->lastModified($file);
            }
        }

        return $out;
    }
}
