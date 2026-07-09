<?php

declare(strict_types=1);

namespace SidewalkDevelopers\PlatformAgent\Console;

use SidewalkDevelopers\PlatformAgent\Console\Concerns\ReportsAgentTelemetry;
use SidewalkDevelopers\PlatformAgent\Credentials\CredentialStore;
use SidewalkDevelopers\PlatformAgent\Http\PlatformClient;
use SidewalkDevelopers\PlatformAgent\Reporting\EnvironmentReporter;
use SidewalkDevelopers\PlatformAgent\Telemetry\TelemetryCollector;

/**
 * `platform-agent:report` — richer, less-frequent health/version/environment
 * telemetry (PA2). POST /api/v1/agent/report (ability app:heartbeat).
 *
 * Same envelope as heartbeat but with richer metadata (queue, scheduler, OS).
 * Since v1.1.0 the default `--status=auto` sends the COMPUTED health (degraded
 * when the last run of any backup kind failed or disk free is below the
 * configured floor); an EXPLICIT `--status=healthy|degraded|unreachable`
 * always wins. Bytes only (Rule 1): no usage percentage. Soft version_warning
 * => warn + continue; HTTP 426 => hard upgrade block.
 */
final class ReportCommand extends AbstractAgentCommand
{
    use ReportsAgentTelemetry;

    private const STATUSES = ['healthy', 'degraded', 'unreachable'];

    protected $signature = 'platform-agent:report
        {--status=auto : Reported health: healthy, degraded, unreachable — or auto (computed)}';

    protected $description = 'Send a richer health/version/environment report to the Cloud Hub.';

    protected string $implementedInPhase = 'PA2';

    public function handle(
        PlatformClient $client,
        CredentialStore $credentials,
        EnvironmentReporter $env,
        TelemetryCollector $telemetry,
    ): int {
        $status = (string) $this->option('status');

        if ($status !== 'auto' && ! in_array($status, self::STATUSES, true)) {
            $this->components->error('Invalid --status "'.$status.'". Use one of: '.implode(', ', self::STATUSES).' or auto.');

            return self::FAILURE;
        }

        if (! $this->requireRuntimeToken($credentials)) {
            return self::FAILURE;
        }

        // The CLI override wins; `auto` (the scheduled default) is computed.
        if ($status === 'auto') {
            $status = $telemetry->status();
        }

        $payload = $env->snapshot(
            $status,
            array_merge($this->metadata($env), $telemetry->metadata()),
            $telemetry->extra(),
        );

        return $this->sendTelemetry(fn () => $client->report($payload), 'Report');
    }

    /**
     * Richer (vs heartbeat) non-secret environment context.
     *
     * @return array<string, mixed>
     */
    private function metadata(EnvironmentReporter $env): array
    {
        return [
            'trigger' => 'report',
            'os' => $env->osLabel(),
            'queue_connection' => (string) config('queue.default'),
            'environment' => (string) config('app.env'),
        ];
    }
}
