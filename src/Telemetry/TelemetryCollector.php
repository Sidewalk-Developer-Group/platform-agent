<?php

declare(strict_types=1);

namespace SidewalkDevelopers\PlatformAgent\Telemetry;

use FilesystemIterator;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Psr\Log\LoggerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SidewalkDevelopers\PlatformAgent\State\AgentStateStore;

/**
 * Collects the REAL telemetry facts heartbeat/report send on the wire (v1.1.0).
 *
 * Fills the `$extra` seam of {@see \SidewalkDevelopers\PlatformAgent\Reporting\EnvironmentReporter::snapshot()}
 * with values that are measured or recorded — never invented:
 *
 *  - `last_backup_at` — latest successful run from the local state store.
 *  - `storage_usage_bytes` — recursive size of `telemetry.storage_paths`
 *    (default: the app's storage directory), cached for
 *    `telemetry.cache_ttl_seconds` so the 5-minute heartbeat stays cheap.
 *  - metadata `disk_free_bytes` / `disk_total_bytes` for the app base path
 *    (metadata until the Hub contract promotes them to first-class columns).
 *
 * Computed status (Rule 1 compliant — bytes only, no percentage):
 * `degraded` when the most recent run of any backup kind failed, or when the
 * base-path free space drops below `telemetry.min_free_bytes` (0 = disabled);
 * `healthy` otherwise. `unreachable` is never computed here — that verdict
 * belongs to the Hub when the agent goes silent.
 *
 * Every probe is null-safe: unreadable paths are skipped, a failed measurement
 * omits the field instead of fabricating a value.
 */
final class TelemetryCollector
{
    private const CACHE_KEY_PREFIX = 'platform-agent:storage-usage:';

    public function __construct(
        private readonly AgentStateStore $state,
        private readonly CacheRepository $cache,
        private readonly ConfigRepository $config,
        private readonly Application $app,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @return 'healthy'|'degraded'
     */
    public function status(): string
    {
        return $this->degradedReasons() === [] ? 'healthy' : 'degraded';
    }

    /**
     * Why the computed status is degraded (empty = healthy). Also surfaced in
     * the heartbeat metadata so the Hub can show the cause, not just the state.
     *
     * @return list<string>
     */
    public function degradedReasons(): array
    {
        $reasons = [];

        foreach ($this->state->failedBackupKinds() as $kind) {
            $reasons[] = 'last_'.$kind.'_backup_failed';
        }

        $minFree = (int) $this->config->get('platform-agent.telemetry.min_free_bytes', 0);

        if ($minFree > 0) {
            $free = $this->diskFreeBytes();

            if ($free !== null && $free < $minFree) {
                $reasons[] = 'low_disk_free';
            }
        }

        return $reasons;
    }

    /**
     * Known wire fields for the snapshot `$extra` seam. Fields the agent cannot
     * measure are OMITTED — never sent as fabricated zeros (Rule 1 spirit).
     *
     * @return array<string, mixed>
     */
    public function extra(): array
    {
        $extra = [];

        $lastBackupAt = $this->state->lastSuccessfulBackupAt();
        if ($lastBackupAt !== null) {
            $extra['last_backup_at'] = $lastBackupAt->format(DATE_ATOM);
        }

        $usage = $this->storageUsageBytes();
        if ($usage !== null) {
            $extra['storage_usage_bytes'] = $usage;
        }

        return $extra;
    }

    /**
     * Disk facts + degradation reasons for the free-form `metadata` field (the
     * Hub persists metadata wholesale; disk_* await first-class Hub columns).
     *
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        $metadata = [];

        $free = $this->diskFreeBytes();
        if ($free !== null) {
            $metadata['disk_free_bytes'] = $free;
        }

        $total = $this->diskTotalBytes();
        if ($total !== null) {
            $metadata['disk_total_bytes'] = $total;
        }

        $reasons = $this->degradedReasons();
        if ($reasons !== []) {
            $metadata['status_reasons'] = $reasons;
        }

        return $metadata;
    }

    /**
     * Recursive size of the configured storage paths, cached so the 5-minute
     * heartbeat never repeatedly walks a large tree. Null when NOTHING is
     * measurable (all paths missing/unreadable) — never a fabricated 0.
     */
    public function storageUsageBytes(): ?int
    {
        $paths = $this->storagePaths();

        if ($paths === []) {
            return null;
        }

        $ttl = (int) $this->config->get('platform-agent.telemetry.cache_ttl_seconds', 1800);

        if ($ttl <= 0) {
            return $this->measure($paths);
        }

        try {
            return $this->cache->remember(
                self::CACHE_KEY_PREFIX.sha1(implode('|', $paths)),
                $ttl,
                fn (): ?int => $this->measure($paths),
            );
        } catch (\Throwable $e) {
            // A broken cache store must not kill telemetry — measure directly.
            $this->logger?->warning('platform-agent.telemetry.cache_unavailable', [
                'reason' => $e->getMessage(),
            ]);

            return $this->measure($paths);
        }
    }

    public function diskFreeBytes(): ?int
    {
        $free = @disk_free_space($this->app->basePath());

        return $free === false ? null : (int) $free;
    }

    public function diskTotalBytes(): ?int
    {
        $total = @disk_total_space($this->app->basePath());

        return $total === false ? null : (int) $total;
    }

    /**
     * @return list<string>
     */
    private function storagePaths(): array
    {
        $paths = $this->config->get('platform-agent.telemetry.storage_paths');

        if (! is_array($paths) || $paths === []) {
            // Code-side default: published configs predating v1.1.0 lack the key.
            $paths = [$this->app->storagePath()];
        }

        return array_values(array_filter(array_map(
            static fn ($path): string => is_string($path) ? trim($path) : '',
            $paths,
        ), static fn (string $path): bool => $path !== ''));
    }

    /**
     * @param  list<string>  $paths
     */
    private function measure(array $paths): ?int
    {
        $total = null;

        foreach ($paths as $path) {
            if (is_file($path)) {
                $size = @filesize($path);
                $total = ($total ?? 0) + ($size === false ? 0 : $size);

                continue;
            }

            if (is_dir($path) && is_readable($path)) {
                $total = ($total ?? 0) + $this->directorySize($path);

                continue;
            }

            $this->logger?->debug('platform-agent.telemetry.storage_path_skipped', ['path' => $path]);
        }

        return $total;
    }

    private function directorySize(string $directory): int
    {
        $bytes = 0;

        try {
            // CATCH_GET_CHILD skips unreadable subtrees instead of aborting the
            // walk; hasChildren() never follows directory symlinks (no cycles).
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY,
                RecursiveIteratorIterator::CATCH_GET_CHILD,
            );

            foreach ($iterator as $file) {
                try {
                    if ($file->isFile() && ! $file->isLink()) {
                        $bytes += $file->getSize();
                    }
                } catch (\Throwable) {
                    // Race (file deleted mid-walk) or permission — skip it.
                }
            }
        } catch (\Throwable $e) {
            $this->logger?->debug('platform-agent.telemetry.storage_walk_failed', [
                'path' => $directory,
                'reason' => $e->getMessage(),
            ]);
        }

        return $bytes;
    }
}
