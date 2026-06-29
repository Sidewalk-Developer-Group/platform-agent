<?php

declare(strict_types=1);

namespace SidewalkDevelopers\PlatformAgent\Console;

/**
 * `platform-agent:report` — richer, less-frequent health/version/environment
 * telemetry (PA2). POST /api/v1/agent/report (ability app:heartbeat).
 */
final class ReportCommand extends AbstractAgentCommand
{
    protected $signature = 'platform-agent:report';

    protected $description = 'Send a richer health/version/environment report to the Cloud Hub.';

    protected string $implementedInPhase = 'PA2';

    public function handle(): int
    {
        return $this->notImplemented();
    }
}
