<?php

declare(strict_types=1);

namespace SidewalkDevelopers\PlatformAgent\Console\Concerns;

use Illuminate\Http\Client\ConnectionException;
use SidewalkDevelopers\PlatformAgent\Credentials\CredentialStore;
use SidewalkDevelopers\PlatformAgent\Exceptions\AgentUpgradeRequiredException;
use SidewalkDevelopers\PlatformAgent\Exceptions\MissingCredentialException;
use SidewalkDevelopers\PlatformAgent\Http\AgentResponse;
use SidewalkDevelopers\PlatformAgent\Http\PlatformClient;
use SidewalkDevelopers\PlatformAgent\Reporting\EnvironmentReporter;

/**
 * Shared enrollment-exchange orchestration for the commands that pair an agent
 * (`platform-agent:install` and `platform-agent:register`). Runs the
 * enrollment-token -> runtime-PAT exchange and persists the rotated runtime
 * token; each command renders its own surrounding UX (PA2).
 *
 * @mixin \Illuminate\Console\Command
 */
trait RunsEnrollmentExchange
{
    /**
     * Run POST /api/v1/agent/register with the enrollment bearer. Returns the
     * parsed envelope on success, or null after rendering an actionable error
     * (connectivity, 401, 426, validation). A soft version_warning is surfaced
     * and does NOT fail the exchange.
     */
    protected function runEnrollment(PlatformClient $client, EnvironmentReporter $env): ?AgentResponse
    {
        try {
            $result = $client->register($env->registerPayload());
        } catch (AgentUpgradeRequiredException $e) {
            $this->components->error('Platform Agent upgrade required: '.$e->getMessage());
            $this->line('  Upgrade the package (composer update sidewalkdevelopers/platform-agent) and re-run.');

            return null;
        } catch (ConnectionException $e) {
            $this->components->error('Could not reach the Cloud Hub at '.config('platform-agent.url').': '.$e->getMessage());

            return null;
        } catch (MissingCredentialException $e) {
            $this->components->error($e->getMessage());

            return null;
        }

        if ($result->failed()) {
            $this->components->error('Enrollment failed ('.$result->status.'): '.($result->message ?? 'unknown error'));

            if ($result->status === 401) {
                $this->line('  The enrollment token is invalid or already consumed. Ask your operator to mint a fresh one.');
            }

            foreach (($result->errors ?? []) as $field => $messages) {
                foreach ((array) $messages as $message) {
                    $this->line("  - {$field}: {$message}");
                }
            }

            return null;
        }

        if ($result->hasVersionWarning()) {
            $this->components->warn('Version notice (operations continue): '.$result->versionWarning);
        }

        $this->verifyApplicationMatch($result);

        return $result;
    }

    /**
     * Persist the rotated runtime PAT (encrypted) from the register response.
     * Returns false (after an error) when the Hub returned no runtime token —
     * i.e. an Agent Contract older than v1.2.0 (enrollment-exchange).
     */
    protected function persistRuntimeToken(AgentResponse $result, CredentialStore $credentials): bool
    {
        $runtime = $result->runtimeToken();

        if ($runtime === null || blank($runtime['token'] ?? null)) {
            $this->components->error(
                'The Hub did not return a runtime token. Ensure the Hub Agent Contract is v1.2.0+ (enrollment-exchange).'
            );

            return false;
        }

        $credentials->putRuntimeToken((string) $runtime['token'], [
            'token_id' => $runtime['token_id'] ?? null,
            'abilities' => $runtime['abilities'] ?? [],
            'expires_at' => $runtime['expires_at'] ?? null,
            'application_uuid' => config('platform-agent.application_uuid'),
        ]);

        return true;
    }

    /**
     * Warn (never fail) when the configured PLATFORM_APPLICATION_UUID disagrees
     * with the token-bound Application. The token is authoritative for identity.
     */
    protected function verifyApplicationMatch(AgentResponse $result): void
    {
        $bound = $result->get('registration.application_id');
        $configured = config('platform-agent.application_uuid');

        if ($bound !== null && $configured !== null && $bound !== $configured) {
            $this->components->warn(sprintf(
                'PLATFORM_APPLICATION_UUID (%s) does not match the token-bound Application (%s). '
                .'Identity is the token-bound Application; double-check your config.',
                $configured,
                $bound,
            ));
        }
    }
}
