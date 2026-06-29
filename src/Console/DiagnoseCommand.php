<?php

declare(strict_types=1);

namespace SidewalkDevelopers\PlatformAgent\Console;

/**
 * `platform-agent:diagnose` — onboarding/health aid (PA1).
 *
 * Will print resolved config (token REDACTED), connectivity, last heartbeat /
 * backup, current agent version vs the Hub verdict, and any surfaced
 * version_warning (ADR-0007 §2.4).
 */
final class DiagnoseCommand extends AbstractAgentCommand
{
    protected $signature = 'platform-agent:diagnose';

    protected $description = 'Print resolved agent config (token redacted), connectivity and version status.';

    protected string $implementedInPhase = 'PA1';

    public function handle(): int
    {
        return $this->notImplemented();
    }
}
