<?php

declare(strict_types=1);

namespace SidewalkDevelopers\PlatformAgent\Console;

use SidewalkDevelopers\PlatformAgent\Console\Concerns\ReportsAgentTelemetry;
use SidewalkDevelopers\PlatformAgent\Credentials\CredentialStore;
use SidewalkDevelopers\PlatformAgent\Http\PlatformClient;
use SidewalkDevelopers\PlatformAgent\Reporting\EnvironmentReporter;

/**
 * `platform-agent:report` — richer, less-frequent health/version/environment
 * telemetry (PA2). POST /api/v1/agent/report (ability app:heartbeat).
 *
 * Same envelope as heartbeat but with richer metadata (queue, scheduler, OS) and
 * a caller-supplied health `--status` (healthy|degraded|unreachable). Bytes only
 * (Rule 1): no usage percentage. Soft version_warning => warn + continue;
 * HTTP 426 => hard upgrade block.
 */
final class ReportCommand extends AbstractAgentCommand
{
    use ReportsAgentTelemetry;

    private const STATUSES = ['healthy', 'degraded', 'unreachable'];

    protected $signature = 'platform-agent:report
        {--status=healthy : Reported health: healthy, degraded or unreachable}';

    protected $description = 'Send a richer health/version/environment report to the Cloud Hub.';

    protected string $implementedInPhase = 'PA2';

    public function handle(PlatformClient $client, CredentialStore $credentials, EnvironmentReporter $env): int
    {
        $status = (string) $this->option('status');

        if (! in_array($status, self::STATUSES, true)) {
            $this->components->error('Invalid --status "'.$status.'". Use one of: '.implode(', ', self::STATUSES).'.');

            return self::FAILURE;
        }

        if (! $this->requireRuntimeToken($credentials)) {
            return self::FAILURE;
        }

        $payload = $env->snapshot($status, $this->metadata($env));

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
