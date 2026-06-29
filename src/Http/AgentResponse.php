<?php

declare(strict_types=1);

namespace SidewalkDevelopers\PlatformAgent\Http;

use Illuminate\Support\Arr;

/**
 * A typed parse of the Hub `ApiResponse` envelope
 * `{ success, message, data, errors, meta }` (CLAUDE.md API Standards).
 *
 * `version_warning` (a soft-lag message string when the agent is below the Hub's
 * `recommended_min` but at/above `compatible_floor`) is surfaced as a first-class
 * field. A warning is NEVER a failure — the operation still succeeded; the
 * package logs it and continues (ADR-0007 §2.5).
 */
final class AgentResponse
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $errors
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly int $status,
        public readonly bool $success,
        public readonly ?string $message,
        public readonly array $data,
        public readonly ?array $errors,
        public readonly array $meta,
        public readonly ?string $versionWarning,
        public readonly bool $dataWasNull,
    ) {
    }

    /**
     * Build from the decoded JSON body of an envelope response.
     *
     * @param  array<string, mixed>  $body
     */
    public static function fromEnvelope(int $status, array $body): self
    {
        $rawData = $body['data'] ?? null;
        $dataWasNull = ! is_array($rawData);
        $data = is_array($rawData) ? $rawData : [];

        // `version_warning` is nested under `data` on register/heartbeat/report.
        $versionWarning = $data['version_warning'] ?? null;
        $versionWarning = is_string($versionWarning) && $versionWarning !== ''
            ? $versionWarning
            : null;

        $errors = $body['errors'] ?? null;
        $meta = $body['meta'] ?? [];

        return new self(
            status: $status,
            success: (bool) ($body['success'] ?? false),
            message: isset($body['message']) ? (string) $body['message'] : null,
            data: $data,
            errors: is_array($errors) ? $errors : null,
            meta: is_array($meta) ? $meta : [],
            versionWarning: $versionWarning,
            dataWasNull: $dataWasNull,
        );
    }

    public function failed(): bool
    {
        return ! $this->success;
    }

    public function hasVersionWarning(): bool
    {
        return $this->versionWarning !== null;
    }

    /**
     * Dot-access into the `data` payload.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->data, $key, $default);
    }
}
