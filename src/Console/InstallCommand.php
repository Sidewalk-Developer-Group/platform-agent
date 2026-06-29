<?php

declare(strict_types=1);

namespace SidewalkDevelopers\PlatformAgent\Console;

/**
 * `platform-agent:install` — near-zero-code onboarding (PA1).
 *
 * Will publish config, validate the 3 env vars, perform the enrollment ->
 * runtime PAT exchange via POST /api/v1/agent/register, persist the runtime PAT
 * encrypted in the customer DB, wire the schedule, and run a connectivity/auth
 * pre-flight (ADR-0007 §2.3 / Addendum D).
 */
final class InstallCommand extends AbstractAgentCommand
{
    protected $signature = 'platform-agent:install
        {--force : Overwrite an existing published config}';

    protected $description = 'Onboard this application to the Cloud Hub (publish config, enroll, persist runtime token, wire schedule).';

    protected string $implementedInPhase = 'PA1';

    public function handle(): int
    {
        return $this->notImplemented();
    }
}
