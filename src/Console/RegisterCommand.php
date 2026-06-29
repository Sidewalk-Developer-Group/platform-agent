<?php

declare(strict_types=1);

namespace SidewalkDevelopers\PlatformAgent\Console;

/**
 * `platform-agent:register` — (re)pair the agent (PA2).
 *
 * Reports agent_version + host/fingerprint to POST /api/v1/agent/register,
 * handling the soft version_warning (log + continue) vs the 426 hard-block.
 */
final class RegisterCommand extends AbstractAgentCommand
{
    protected $signature = 'platform-agent:register';

    protected $description = 'Register / re-pair this agent with the Cloud Hub (version + host/fingerprint).';

    protected string $implementedInPhase = 'PA2';

    public function handle(): int
    {
        return $this->notImplemented();
    }
}
