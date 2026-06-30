<?php

declare(strict_types=1);

namespace SidewalkDevelopers\PlatformAgent\Backup;

use DateTimeInterface;
use SidewalkDevelopers\PlatformAgent\Http\PlatformClient;
use SidewalkDevelopers\PlatformAgent\Http\TusUploadClient;

/**
 * Selects the upload transport by archive size and uploads the verified pair (PA3).
 *
 * Below `backup.tus.threshold_bytes` → single-POST multipart `/agent/archives`
 * (small + simple). At/above the threshold → the resumable tus surface
 * `/agent/uploads` (GB → hundreds of GB). Either way the Hub recomputes the
 * full-file SHA256 from disk and returns the authoritative Rule-4 verdict; this
 * class just routes and normalizes the outcome into an {@see UploadResult}.
 */
final class ArchiveUploader
{
    /** @var array<string, mixed> */
    private array $config;

    public function __construct(
        private readonly PlatformClient $client,
        private readonly TusUploadClient $tus,
        array $config,
    ) {
        $this->config = $config;
    }

    /**
     * @param  'database'|'files'  $kind
     */
    public function upload(
        string $kind,
        string $archivePath,
        string $sidecarPath,
        string $checksum,
        int $sizeBytes,
        string $agentRunUuid,
        DateTimeInterface $uploadedAt,
    ): UploadResult {
        $filename = basename($archivePath);

        if ($sizeBytes >= $this->thresholdBytes()) {
            return $this->tus->upload($archivePath, $filename, $kind, $checksum, $agentRunUuid);
        }

        $response = $this->client->uploadArchive(
            fields: [
                'filename' => $filename,
                'kind' => $kind,
                'checksum' => $checksum,
                'uploaded_at' => $uploadedAt->format(DATE_ATOM),
            ],
            files: [
                'file' => $archivePath,
                'sidecar' => $sidecarPath,
            ],
        );

        if ($response->failed()) {
            throw new \RuntimeException(
                'Archive upload rejected ('.$response->status.'): '.($response->message ?? 'unknown error'),
            );
        }

        return new UploadResult(
            archiveId: is_string($id = $response->get('id')) ? $id : null,
            status: is_string($status = $response->get('status')) ? $status : 'unknown',
            via: 'single',
        );
    }

    private function thresholdBytes(): int
    {
        return (int) ($this->config['backup']['tus']['threshold_bytes'] ?? 268435456);
    }
}
