<?php

declare(strict_types=1);

namespace SidewalkDevelopers\PlatformAgent\Reporting;

use DateTimeInterface;
use SidewalkDevelopers\PlatformAgent\Http\AgentResponse;
use SidewalkDevelopers\PlatformAgent\Http\PlatformClient;

/**
 * Posts the backup run-log to the Hub ingest `/agent/backup-runs` (ADR-0008; PA3).
 *
 * Every run is reported TWICE on the same `agent_run_uuid` (the idempotency key):
 * a non-terminal `running` START, then a terminal `success` or `failed`. The Hub
 * upserts `running → terminal` to one row; failures MUST be reported even when no
 * heartbeat is due, so the Coverage KPI and R7 portal see them. `error_message`
 * is sanitized — never a secret/PAT/credentialed DSN.
 *
 * Split backups (ADR-0008 §2.9): `database` and `files` are SEPARATE runs, each
 * with its own `agent_run_uuid` + `kind`.
 */
final class BackupRunReporter
{
    public function __construct(
        private readonly PlatformClient $client,
        private readonly EnvironmentReporter $env,
    ) {
    }

    public function reportRunning(
        string $agentRunUuid,
        string $kind,
        string $spatieName,
        DateTimeInterface $startedAt,
        string $trigger,
    ): AgentResponse {
        return $this->client->backupRun([
            'agent_run_uuid' => $agentRunUuid,
            'kind' => $kind,
            'status' => 'running',
            'trigger' => $trigger,
            'started_at' => $startedAt->format(DATE_ATOM),
            'spatie_backup_name' => $spatieName,
            'agent_version' => $this->env->agentVersion(),
        ]);
    }

    public function reportSuccess(
        string $agentRunUuid,
        string $kind,
        string $spatieName,
        DateTimeInterface $startedAt,
        DateTimeInterface $finishedAt,
        int $sizeBytes,
        string $checksum,
        ?string $backupArchiveId,
        string $trigger,
    ): AgentResponse {
        return $this->client->backupRun([
            'agent_run_uuid' => $agentRunUuid,
            'kind' => $kind,
            'status' => 'success',
            'trigger' => $trigger,
            'started_at' => $startedAt->format(DATE_ATOM),
            'finished_at' => $finishedAt->format(DATE_ATOM),
            'spatie_backup_name' => $spatieName,
            'size_bytes' => $sizeBytes,
            'checksum' => $checksum,
            'backup_archive_id' => $backupArchiveId,
            'agent_version' => $this->env->agentVersion(),
        ]);
    }

    public function reportFailed(
        string $agentRunUuid,
        string $kind,
        string $spatieName,
        DateTimeInterface $startedAt,
        DateTimeInterface $finishedAt,
        string $errorMessage,
        string $trigger,
    ): AgentResponse {
        return $this->client->backupRun([
            'agent_run_uuid' => $agentRunUuid,
            'kind' => $kind,
            'status' => 'failed',
            'trigger' => $trigger,
            'started_at' => $startedAt->format(DATE_ATOM),
            'finished_at' => $finishedAt->format(DATE_ATOM),
            'spatie_backup_name' => $spatieName,
            'error_message' => self::sanitize($errorMessage),
            'agent_version' => $this->env->agentVersion(),
        ]);
    }

    /**
     * Strip secrets from a failure message before it leaves the customer host:
     * bearer tokens, `password=...` / `pwd=...` pairs, and DSN credentials. No
     * silent failures — but no leaked secrets either (ADR-0008 §2 agent notes).
     */
    public static function sanitize(string $message, int $maxLength = 2000): string
    {
        $patterns = [
            '/(password|passwd|pwd|secret|token)\s*[=:]\s*\S+/i' => '$1=[redacted]',
            '/Bearer\s+\S+/i' => 'Bearer [redacted]',
            '/\/\/([^:\/@\s]+):([^@\s]+)@/' => '//$1:[redacted]@', // user:pass@host DSNs
        ];

        $clean = (string) preg_replace(array_keys($patterns), array_values($patterns), $message);
        $clean = trim($clean);

        return mb_strlen($clean) > $maxLength ? mb_substr($clean, 0, $maxLength).'…' : $clean;
    }
}
