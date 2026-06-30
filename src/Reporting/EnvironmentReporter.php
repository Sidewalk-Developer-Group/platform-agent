<?php

declare(strict_types=1);

namespace SidewalkDevelopers\PlatformAgent\Reporting;

use DateTimeInterface;
use Illuminate\Contracts\Foundation\Application;

/**
 * Single source of the environment facts the agent reports on the wire (PA2).
 *
 * Shared by register (host/fingerprint pairing key) and heartbeat/report
 * (version + framework + status telemetry) so all three surfaces report
 * identical, consistently-derived values. The fingerprint is the stable pairing
 * key — a re-install / re-pair from the same checkout hits the same
 * agent_registration row (idempotency on (application_id, fingerprint)).
 *
 * Rule 1 (bytes-only): {@see snapshot()} NEVER emits a usage percentage. The
 * agent reports raw bytes (storage_usage_bytes), which the Hub derives
 * percentages from; the agent must not fabricate or store a percentage. Storage
 * usage / last-backup values come from the backup subsystem (PA3) and are merged
 * in by the caller when known — never invented here.
 */
final class EnvironmentReporter
{
    /** @var array<string, mixed> */
    private array $config;

    /**
     * @param  array<string, mixed>  $config  the resolved `platform-agent` config array
     */
    public function __construct(
        private readonly Application $app,
        array $config,
    ) {
        $this->config = $config;
    }

    public function agentVersion(): string
    {
        return (string) ($this->config['agent_version'] ?? '0.0.0');
    }

    public function phpVersion(): string
    {
        return PHP_VERSION;
    }

    public function frameworkVersion(): string
    {
        return 'Laravel '.$this->app->version();
    }

    public function hostname(): string
    {
        return gethostname() ?: 'unknown-host';
    }

    public function osLabel(): string
    {
        return trim(php_uname('s').' '.php_uname('r'));
    }

    /**
     * Stable per-install pairing key. Derived from the bound Application UUID,
     * the host, and the install path so the same checkout always re-pairs the
     * same agent_registration row. Never contains a secret.
     */
    public function fingerprint(): string
    {
        $seed = implode('|', [
            (string) ($this->config['application_uuid'] ?? ''),
            $this->hostname(),
            $this->app->basePath(),
        ]);

        return 'sha256:'.hash('sha256', $seed);
    }

    /**
     * Payload for POST /api/v1/agent/register (enrollment exchange). Identity is
     * the token's bound Application — application_id is NEVER sent.
     *
     * @return array<string, mixed>
     */
    public function registerPayload(): array
    {
        return [
            'agent_version' => $this->agentVersion(),
            'hostname' => $this->hostname(),
            'fingerprint' => $this->fingerprint(),
            'metadata' => [
                'os' => $this->osLabel(),
                'php' => $this->phpVersion(),
            ],
        ];
    }

    /**
     * Telemetry snapshot for heartbeat/report. Bytes-only (Rule 1): no usage
     * percentage is ever included. storage_usage_bytes / last_backup_at are
     * merged in by the caller through $extra only when the backup subsystem
     * (PA3) can supply real values — they are never fabricated here.
     *
     * @param  'healthy'|'degraded'|'unreachable'  $status
     * @param  array<string, mixed>  $metadata  free-form context (queue, scheduler, ...)
     * @param  array<string, mixed>  $extra     known wire fields (e.g. storage_usage_bytes)
     * @return array<string, mixed>
     */
    public function snapshot(string $status, array $metadata = [], array $extra = [], ?DateTimeInterface $recordedAt = null): array
    {
        $recordedAt ??= now();

        return array_merge([
            'agent_version' => $this->agentVersion(),
            'php_version' => $this->phpVersion(),
            'framework_version' => $this->frameworkVersion(),
            'status' => $status,
            'metadata' => $metadata,
            'recorded_at' => $recordedAt->format(DATE_ATOM),
        ], $extra);
    }
}
