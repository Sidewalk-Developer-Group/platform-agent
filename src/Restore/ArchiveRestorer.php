<?php

declare(strict_types=1);

namespace SidewalkDevelopers\PlatformAgent\Restore;

use Psr\Log\LoggerInterface;
use SidewalkDevelopers\PlatformAgent\Exceptions\AgentUpgradeRequiredException;
use SidewalkDevelopers\PlatformAgent\Http\PlatformClient;

/**
 * Pulls one approved restore job's archive from the Hub and deposits it (PA4 /
 * ADR-0011). The Hub NEVER pushes into customer infra: the agent fetches the
 * NON-MUTATING manifest, pulls the bytes off the signed egress URL, VERIFIES the
 * SHA256 (Rule 4) BEFORE finalizing, then DEPOSITS the verified `backup.zip` +
 * a `.sha256` sidecar at the target. It is NON-DESTRUCTIVE — the customer applies
 * the archive; the agent does not extract or import it.
 *
 * A checksum mismatch aborts: the partial download is deleted and a Rule-4
 * failure {@see RestoreResult} is returned (the command reports it to the Hub).
 */
final class ArchiveRestorer
{
    public function __construct(
        private readonly PlatformClient $client,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Fetch manifest → download bytes → verify SHA256 → deposit at $targetLocation.
     *
     * $targetLocation may be an existing directory (the archive lands as
     * "{dir}/{filename}") or a full destination file path (used verbatim).
     */
    public function pull(string $restoreJobId, string $targetLocation): RestoreResult
    {
        $manifest = $this->client->restoreManifest($restoreJobId);

        if ($manifest->failed()) {
            return RestoreResult::failed(
                'Could not fetch the restore manifest ('.$manifest->status.'): '.($manifest->message ?? 'unknown error.'),
            );
        }

        $filename = (string) $manifest->get('archive.filename');
        $expected = strtolower((string) $manifest->get('archive.sha256'));
        $downloadUrl = (string) $manifest->get('download_url');
        $size = (int) ($manifest->get('archive.size_bytes') ?? 0);

        if ($filename === '' || $expected === '' || $downloadUrl === '') {
            return RestoreResult::failed(
                'Restore manifest is incomplete (missing filename, sha256 or download_url).',
                $filename !== '' ? $filename : null,
            );
        }

        // Stage the download to a local temp file (memory-safe for GB archives).
        $stage = (string) tempnam(sys_get_temp_dir(), 'pa-restore-');

        try {
            $status = $this->client->downloadArchive($downloadUrl, $stage);
        } catch (AgentUpgradeRequiredException $e) {
            // A hard-block (426) must surface as an upgrade error, never be
            // swallowed into a reportable failure (reporting would 426 too).
            $this->cleanup($stage);

            throw $e;
        } catch (\Throwable $e) {
            $this->cleanup($stage);

            return RestoreResult::failed('Archive download failed: '.$e->getMessage(), $filename);
        }

        if ($status < 200 || $status >= 300) {
            $this->cleanup($stage);

            return RestoreResult::failed('Archive download returned HTTP '.$status.'.', $filename);
        }

        // Rule 4: verify the pulled bytes BEFORE depositing.
        $actual = strtolower((string) hash_file('sha256', $stage));

        if (! hash_equals($expected, $actual)) {
            $this->cleanup($stage);

            $this->logger?->error('platform-agent.restore.checksum_mismatch', [
                'restore_job_id' => $restoreJobId,
                'filename' => $filename,
            ]);

            return RestoreResult::mismatch($filename, $expected, $actual);
        }

        // Deposit the verified archive + sidecar (non-destructive).
        try {
            $destination = $this->resolveDestination($targetLocation, $filename);
            $this->ensureDirectory(dirname($destination));

            if (! @rename($stage, $destination)) {
                // rename fails across filesystems — fall back to a stream copy.
                if (! @copy($stage, $destination)) {
                    throw new \RuntimeException('Unable to write archive to '.$destination);
                }
                $this->cleanup($stage);
            }

            file_put_contents($destination.'.sha256', $actual.'  '.basename($destination).PHP_EOL);
        } catch (\Throwable $e) {
            $this->cleanup($stage);

            return RestoreResult::failed('Deposit failed: '.$e->getMessage(), $filename);
        }

        $bytes = $size > 0 ? $size : (int) filesize($destination);

        $this->logger?->info('platform-agent.restore.deposited', [
            'restore_job_id' => $restoreJobId,
            'filename' => $filename,
            'path' => $destination,
            'size_bytes' => $bytes,
        ]);

        return RestoreResult::success($destination, $filename, $actual, $bytes);
    }

    /**
     * Resolve the deposit path: a directory target → "{dir}/{filename}"; anything
     * else is treated as the full destination file path.
     */
    private function resolveDestination(string $target, string $filename): string
    {
        $trimmed = rtrim($target, DIRECTORY_SEPARATOR);

        if (is_dir($target) || $target === '' || str_ends_with($target, DIRECTORY_SEPARATOR)) {
            return ($trimmed === '' ? '.' : $trimmed).DIRECTORY_SEPARATOR.$filename;
        }

        return $target;
    }

    private function ensureDirectory(string $dir): void
    {
        if ($dir !== '' && ! is_dir($dir) && ! @mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            throw new \RuntimeException('Unable to create target directory: '.$dir);
        }
    }

    private function cleanup(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
