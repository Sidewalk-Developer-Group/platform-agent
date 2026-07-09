<?php

declare(strict_types=1);

namespace SidewalkDevelopers\PlatformAgent\State;

use DateTimeInterface;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/**
 * Durable NON-SECRET operational state (v1.1.0).
 *
 * Plaintext key/value JSON in the customer DB (`platform_agent_state`) — the
 * non-secret sibling of the encrypted {@see \SidewalkDevelopers\PlatformAgent\Credentials\DatabaseCredentialStore}.
 * It caches the LATEST local facts the telemetry surfaces need without a Hub
 * round-trip:
 *
 *  - `backup_run.{kind}` — outcome of the most recent backup run per kind
 *    (feeds `last_backup_at` and the computed heartbeat status).
 *  - `scheduled_heartbeat` — when the package-scheduled heartbeat last ran
 *    (feeds the diagnose scheduler-freshness check).
 *
 * The Hub's `/agent/backup-runs` ingest remains the authoritative run catalog
 * (Rule 3); this store never replaces it. Every read/write is NULL-SAFE against
 * a missing table (a customer who upgraded the package but has not run
 * `php artisan migrate` yet) — telemetry degrades to "unknown", it never breaks
 * a backup or heartbeat.
 */
final class AgentStateStore
{
    private const KEY_SCHEDULED_HEARTBEAT = 'scheduled_heartbeat';

    private const KEY_BACKUP_RUN_PREFIX = 'backup_run.';

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly ConnectionResolverInterface $db,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function isReady(): bool
    {
        try {
            return $this->db->connection($this->connectionName())
                ->getSchemaBuilder()
                ->hasTable($this->tableName());
        } catch (\Throwable $e) {
            $this->logger?->warning('platform-agent.state_store.readiness_check_failed', [
                'reason' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Persist a state value. NEVER throws — a missing table (package upgraded,
     * `php artisan migrate` not run yet) must not break a backup or heartbeat.
     *
     * @param  array<string, mixed>  $value
     */
    public function put(string $key, array $value): void
    {
        $now = now();

        try {
            $this->table()->updateOrInsert(
                ['key' => $key],
                [
                    'value' => json_encode($value, JSON_THROW_ON_ERROR),
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        } catch (\Throwable $e) {
            $this->logger?->warning('platform-agent.state_store.write_failed', [
                'key' => $key,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>|null null when absent, unreadable or the table is missing
     */
    public function get(string $key): ?array
    {
        try {
            $row = $this->table()->where('key', $key)->first();
        } catch (\Throwable $e) {
            $this->logger?->warning('platform-agent.state_store.read_failed', [
                'key' => $key,
                'reason' => $e->getMessage(),
            ]);

            return null;
        }

        if ($row === null || ! is_string($row->value ?? null)) {
            return null;
        }

        $decoded = json_decode($row->value, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Record the outcome of a backup run for a kind. A failure keeps the
     * previous `last_success_at` so `last_backup_at` stays truthful.
     */
    public function recordBackupRun(string $kind, bool $success, ?DateTimeInterface $finishedAt = null): void
    {
        $finishedAt ??= now();
        $existing = $this->get(self::KEY_BACKUP_RUN_PREFIX.$kind) ?? [];

        $this->put(self::KEY_BACKUP_RUN_PREFIX.$kind, [
            'last_status' => $success ? 'success' : 'failed',
            'last_finished_at' => $finishedAt->format(DATE_ATOM),
            'last_success_at' => $success
                ? $finishedAt->format(DATE_ATOM)
                : ($existing['last_success_at'] ?? null),
        ]);
    }

    /**
     * The most recent SUCCESSFUL backup across all configured kinds — the
     * `last_backup_at` reported on heartbeat/report. Null until a run succeeds.
     */
    public function lastSuccessfulBackupAt(): ?Carbon
    {
        $latest = null;

        foreach ($this->kinds() as $kind) {
            $at = $this->parseTime($this->get(self::KEY_BACKUP_RUN_PREFIX.$kind)['last_success_at'] ?? null);

            if ($at !== null && ($latest === null || $at->greaterThan($latest))) {
                $latest = $at;
            }
        }

        return $latest;
    }

    /**
     * Kinds whose MOST RECENT recorded run failed — a non-empty list degrades
     * the computed heartbeat status.
     *
     * @return list<string>
     */
    public function failedBackupKinds(): array
    {
        $failed = [];

        foreach ($this->kinds() as $kind) {
            if (($this->get(self::KEY_BACKUP_RUN_PREFIX.$kind)['last_status'] ?? null) === 'failed') {
                $failed[] = $kind;
            }
        }

        return $failed;
    }

    /**
     * Mark that the PACKAGE-SCHEDULED heartbeat ran (the `--scheduled` flag the
     * schedule passes). Proves the customer's `schedule:run` cron is alive.
     */
    public function recordScheduledHeartbeat(?DateTimeInterface $at = null): void
    {
        $at ??= now();

        $this->put(self::KEY_SCHEDULED_HEARTBEAT, ['ran_at' => $at->format(DATE_ATOM)]);
    }

    public function lastScheduledHeartbeatAt(): ?Carbon
    {
        return $this->parseTime($this->get(self::KEY_SCHEDULED_HEARTBEAT)['ran_at'] ?? null);
    }

    /**
     * @return list<string> the configured backup kinds (data-driven, never hardcoded)
     */
    private function kinds(): array
    {
        $kinds = $this->config->get('platform-agent.backup.kinds', []);

        return is_array($kinds) ? array_map('strval', array_keys($kinds)) : [];
    }

    private function parseTime(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function connectionName(): ?string
    {
        $connection = $this->config->get('platform-agent.store.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    private function tableName(): string
    {
        // Code-side default: published configs predating v1.1.0 lack this key.
        return (string) $this->config->get('platform-agent.store.state_table', 'platform_agent_state');
    }

    private function table(): Builder
    {
        return $this->db->connection($this->connectionName())->table($this->tableName());
    }
}
