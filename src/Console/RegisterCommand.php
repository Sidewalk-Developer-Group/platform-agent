<?php

declare(strict_types=1);

namespace SidewalkDevelopers\PlatformAgent\Console;

use SidewalkDevelopers\PlatformAgent\Console\Concerns\RunsEnrollmentExchange;
use SidewalkDevelopers\PlatformAgent\Credentials\CredentialStore;
use SidewalkDevelopers\PlatformAgent\Http\PlatformClient;
use SidewalkDevelopers\PlatformAgent\Reporting\EnvironmentReporter;

/**
 * `platform-agent:register` — explicit re-pair (PA2).
 *
 * The standalone re-pair entry point over the enrollment exchange: it reports
 * agent_version + host/fingerprint to POST /api/v1/agent/register with a FRESH
 * operator-minted enrollment token and ROTATES the runtime PAT. Soft
 * version_warning => continue; HTTP 426 => hard upgrade block. Unlike
 * `:install` it publishes no config and prints no onboarding hints — it assumes
 * an already-onboarded app that needs to re-pair (rotated token, moved host,
 * version bump).
 */
final class RegisterCommand extends AbstractAgentCommand
{
    use RunsEnrollmentExchange;

    protected $signature = 'platform-agent:register';

    protected $description = 'Re-pair this agent with the Cloud Hub (rotates the runtime token via a fresh enrollment token).';

    protected string $implementedInPhase = 'PA2';

    public function handle(PlatformClient $client, CredentialStore $credentials, EnvironmentReporter $env): int
    {
        $this->components->info('Re-pairing this agent with the Cloud Hub.');

        if (! $this->validate($credentials)) {
            return self::FAILURE;
        }

        if ($credentials->hasRuntimeToken()) {
            $this->components->warn(
                'An existing runtime token will be ROTATED by this re-pair. The current token stops '
                .'working once the new one is issued.'
            );
        }

        $result = $this->runEnrollment($client, $env);

        if ($result === null) {
            return self::FAILURE;
        }

        if (! $this->persistRuntimeToken($result, $credentials)) {
            return self::FAILURE;
        }

        $this->components->task('Rotated and stored runtime token (encrypted)');
        $this->components->info('Re-pair complete. The new runtime token is now active.');

        return self::SUCCESS;
    }

    private function validate(CredentialStore $credentials): bool
    {
        $ok = true;

        if (blank(config('platform-agent.url'))) {
            $this->components->error('PLATFORM_URL is not set. Set the Cloud Hub base URL in your .env.');
            $ok = false;
        }

        if (blank($credentials->enrollmentToken())) {
            $this->components->error(
                'PLATFORM_TOKEN is not set. Re-pairing needs a FRESH operator-minted enrollment token '
                .'(the previous one was consumed on the last enrollment).'
            );
            $ok = false;
        }

        if (blank(config('platform-agent.application_uuid'))) {
            $this->components->error('PLATFORM_APPLICATION_UUID is not set. Set the bound Application UUID in your .env.');
            $ok = false;
        }

        return $ok;
    }
}
