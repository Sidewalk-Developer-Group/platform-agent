<?php

declare(strict_types=1);

namespace SidewalkDevelopers\PlatformAgent\Console;

/**
 * `platform-agent:heartbeat` — frequent liveness ping (PA2).
 *
 * POST /api/v1/agent/heartbeat (ability app:heartbeat). Reports bytes only
 * (Rule 1) — never a usage percentage.
 */
final class HeartbeatCommand extends AbstractAgentCommand
{
    protected $signature = 'platform-agent:heartbeat';

    protected $description = 'Send a liveness heartbeat to the Cloud Hub.';

    protected string $implementedInPhase = 'PA2';

    public function handle(): int
    {
        return $this->notImplemented();
    }
}
