<?php

declare(strict_types=1);

namespace SidewalkDevelopers\PlatformAgent\Http;

use Illuminate\Http\Client\Factory as HttpFactory;
use SidewalkDevelopers\PlatformAgent\Backup\UploadResult;
use SidewalkDevelopers\PlatformAgent\Exceptions\AgentUpgradeRequiredException;

/**
 * Minimal tus.io resumable-upload (protocol 1.0) CLIENT for large/growing
 * archives → `POST/PATCH /api/v1/agent/uploads` (uploads.md, R4b; PA3).
 *
 * Library note (symmetric with the Hub, ADR-0007 Addendum C): the tus PROTOCOL is
 * pinned, the library is not. The Hub abandoned `ankitpokhrel/tus-php` for a
 * native server; the client side is likewise hand-rolled over the shared
 * Illuminate HTTP factory — testable with `Http::fake()`, no Guzzle/cache
 * coupling, and it speaks the raw tus wire (not the JSON envelope).
 *
 * Flow: creation (`POST`, carries `Upload-Length` + base64 `Upload-Metadata`) →
 * sequential `PATCH` chunks (each with `Upload-Checksum: sha256 <base64>`,
 * advancing by the server's `Upload-Offset`) → the final PATCH returns the
 * Hub's `X-Backup-Archive-Id` + `X-Backup-Checksum-Status` (Rule 4 verdict). The
 * per-application identity is the runtime PAT — `application_id` is NEVER sent.
 */
final class TusUploadClient
{
    private const TUS_VERSION = '1.0.0';

    /** @var array<string, mixed> */
    private array $config;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly PlatformClient $client,
        array $config,
    ) {
        $this->config = $config;
    }

    /**
     * Upload an archive resumably and return the Hub's catalog id + Rule-4 verdict.
     *
     * @param  'database'|'files'  $kind
     *
     * @throws AgentUpgradeRequiredException  when the Hub hard-blocks (426)
     * @throws \RuntimeException              on any non-tus-compliant response
     */
    public function upload(
        string $filePath,
        string $filename,
        string $kind,
        string $checksum,
        string $agentRunUuid,
    ): UploadResult {
        $size = (int) filesize($filePath);
        $location = $this->create($filename, $kind, $checksum, $agentRunUuid, $size);

        $chunkSize = max(1, (int) ($this->config['backup']['tus']['chunk_size_bytes'] ?? 16777216));

        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("Unable to open archive for upload: {$filePath}");
        }

        $offset = 0;
        $archiveId = null;
        $status = 'unknown';

        try {
            while (! feof($handle)) {
                $chunk = fread($handle, $chunkSize);
                if ($chunk === false || $chunk === '') {
                    break;
                }

                [$offset, $archiveId, $status] = $this->patch($location, $offset, $chunk, $archiveId, $status);
            }
        } finally {
            fclose($handle);
        }

        if ($offset !== $size) {
            throw new \RuntimeException(
                "tus upload incomplete: server offset {$offset} of {$size} bytes for {$filename}.",
            );
        }

        return new UploadResult($archiveId, $status, 'tus');
    }

    /**
     * tus creation — returns the resource URL from the `Location` header.
     */
    private function create(string $filename, string $kind, string $checksum, string $agentRunUuid, int $size): string
    {
        $metadata = $this->encodeMetadata([
            'filename' => $filename,
            'kind' => $kind,
            'checksum' => $checksum,
            'agent_run_uuid' => $agentRunUuid,
        ]);

        $response = $this->request()
            ->withHeaders([
                'Tus-Resumable' => self::TUS_VERSION,
                'Upload-Length' => (string) $size,
                'Upload-Metadata' => $metadata,
            ])
            ->post($this->uploadsUrl());

        $this->guardUpgrade($response->status());

        if ($response->status() !== 201) {
            throw new \RuntimeException("tus creation failed ({$response->status()}) for {$filename}.");
        }

        $location = $response->header('Location');
        if ($location === '') {
            throw new \RuntimeException('tus creation succeeded but returned no Location header.');
        }

        return $location;
    }

    /**
     * Append one chunk; returns [newOffset, archiveId, checksumStatus]. The last
     * PATCH carries the Hub's result headers.
     *
     * @return array{0:int,1:?string,2:string}
     */
    private function patch(string $location, int $offset, string $chunk, ?string $archiveId, string $status): array
    {
        $response = $this->request()
            ->withHeaders([
                'Tus-Resumable' => self::TUS_VERSION,
                'Content-Type' => 'application/offset+octet-stream',
                'Upload-Offset' => (string) $offset,
                'Upload-Checksum' => 'sha256 '.base64_encode(hash('sha256', $chunk, true)),
            ])
            ->withBody($chunk, 'application/offset+octet-stream')
            ->patch($location);

        $this->guardUpgrade($response->status());

        if ($response->status() !== 204) {
            throw new \RuntimeException(
                "tus chunk append failed ({$response->status()}) at offset {$offset}.",
            );
        }

        $newOffset = (int) $response->header('Upload-Offset');

        $headerId = $response->header('X-Backup-Archive-Id');
        $headerStatus = $response->header('X-Backup-Checksum-Status');

        return [
            $newOffset,
            $headerId !== '' ? $headerId : $archiveId,
            $headerStatus !== '' ? $headerStatus : $status,
        ];
    }

    /**
     * @param  array<string, string>  $pairs
     */
    private function encodeMetadata(array $pairs): string
    {
        $parts = [];
        foreach ($pairs as $key => $value) {
            $parts[] = $key.' '.base64_encode($value);
        }

        return implode(',', $parts);
    }

    private function request(): \Illuminate\Http\Client\PendingRequest
    {
        $http = $this->config['http'] ?? [];

        return $this->http
            ->withToken($this->client->authToken())
            ->timeout((int) ($http['timeout'] ?? 30))
            ->connectTimeout((int) ($http['connect_timeout'] ?? 10))
            ->withHeaders(['User-Agent' => 'platform-agent/'.$this->agentVersion()]);
    }

    private function uploadsUrl(): string
    {
        return $this->client->baseUrl().'agent/uploads';
    }

    private function guardUpgrade(int $status): void
    {
        if ($status === 426) {
            throw new AgentUpgradeRequiredException('Platform Agent upgrade required.', 'agent/uploads');
        }
    }

    private function agentVersion(): string
    {
        return (string) ($this->config['agent_version'] ?? '0.0.0');
    }
}
