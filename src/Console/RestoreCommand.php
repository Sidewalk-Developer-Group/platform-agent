<?php

declare(strict_types=1);

namespace SidewalkDevelopers\PlatformAgent\Console;

/**
 * `platform-agent:restore {location}` — agent-PULL restore (PA4).
 *
 * The Hub NEVER pushes into customer infra (ADR-0002 A2). On an approved
 * RestoreJob (discovered via Reverb push or the GET /api/v1/agent/restore-jobs
 * poll fallback), the agent downloads backup.zip + .sha256, VERIFIES the SHA256
 * before restoring, and aborts + deletes the partial + reports failure on
 * mismatch. Gated on the Hub R6 restore seam.
 */
final class RestoreCommand extends AbstractAgentCommand
{
    protected $signature = 'platform-agent:restore
        {location? : Target file location to restore into}';

    protected $description = 'Pull, verify (SHA256) and restore an approved backup archive from the Cloud Hub.';

    protected string $implementedInPhase = 'PA4';

    public function handle(): int
    {
        return $this->notImplemented();
    }
}
