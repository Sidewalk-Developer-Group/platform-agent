<?php

declare(strict_types=1);

namespace SidewalkDevelopers\PlatformAgent\Console;

use SidewalkDevelopers\PlatformAgent\Console\Concerns\ReportsAgentTelemetry;
use SidewalkDevelopers\PlatformAgent\Credentials\CredentialStore;
use SidewalkDevelopers\PlatformAgent\Http\PlatformClient;
use SidewalkDevelopers\PlatformAgent\Reporting\EnvironmentReporter;
use SidewalkDevelopers\PlatformAgent\State\AgentStateStore;
use SidewalkDevelopers\PlatformAgent\Telemetry\TelemetryCollector;

/**
 * `platform-agent:heartbeat` — frequent liveness ping (PA2).
 *
 * POST /api/v1/agent/heartbeat (ability app:heartbeat). The lean, every-5-minute
 * beat (Rule 2). Bytes only (Rule 1) — the snapshot NEVER carries a usage
 * percentage. Since v1.1.0 the beat carries REAL telemetry: `last_backup_at`
 * (latest successful local run), `storage_usage_bytes` (measured + cached),
 * disk facts in metadata, and a COMPUTED healthy|degraded status. Soft
 * version_warning => warn + continue; HTTP 426 => hard upgrade block.
 */
final class HeartbeatCommand extends AbstractAgentCommand
{
    use ReportsAgentTelemetry;

    protected $signature = 'platform-agent:heartbeat
        {--scheduled : Set by the package schedule; records scheduler freshness for diagnose}';

    protected $description = 'Send a liveness heartbeat to the Cloud Hub.';

    protected string $implementedInPhase = 'PA2';

    public function handle(
        PlatformClient $client,
        CredentialStore $credentials,
        EnvironmentReporter $env,
        TelemetryCollector $telemetry,
        AgentStateStore $state,
    ): int {
        // Recorded BEFORE any guard: the marker proves the customer's
        // `schedule:run` cron invoked us, independent of enrollment or Hub
        // reachability. `platform-agent:diagnose` warns when it goes stale.
        if ($this->option('scheduled')) {
            $state->recordScheduledHeartbeat();
        }

        if (! $this->requireRuntimeToken($credentials)) {
            return self::FAILURE;
        }

        $payload = $env->snapshot(
            $telemetry->status(),
            array_merge(['trigger' => 'heartbeat'], $telemetry->metadata()),
            $telemetry->extra(),
        );

        return $this->sendTelemetry(fn () => $client->heartbeat($payload), 'Heartbeat');
    }
}
