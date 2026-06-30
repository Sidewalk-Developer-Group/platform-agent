<?php

declare(strict_types=1);

namespace SidewalkDevelopers\PlatformAgent\Console\Concerns;

use Illuminate\Http\Client\ConnectionException;
use SidewalkDevelopers\PlatformAgent\Credentials\CredentialStore;
use SidewalkDevelopers\PlatformAgent\Exceptions\AgentUpgradeRequiredException;
use SidewalkDevelopers\PlatformAgent\Exceptions\MissingCredentialException;
use SidewalkDevelopers\PlatformAgent\Http\AgentResponse;

/**
 * Shared send + error rendering for the operational telemetry commands
 * (`platform-agent:heartbeat` and `platform-agent:report`) (PA2).
 *
 * Version handling mirrors the contract: a soft version_warning on a 2xx is a
 * WARN-and-continue (the heartbeat/report still counted); a 426 is a hard block
 * surfaced as an actionable upgrade error. No silent failures.
 *
 * @mixin \Illuminate\Console\Command
 */
trait ReportsAgentTelemetry
{
    /**
     * Guard: an operational call requires the durable runtime PAT (the
     * enrollment fallback is for onboarding only). Renders an actionable error
     * and returns false when the agent has not been enrolled yet.
     */
    protected function requireRuntimeToken(CredentialStore $credentials): bool
    {
        if (! $credentials->hasRuntimeToken()) {
            $this->components->error('Not enrolled — no runtime token. Run `php artisan platform-agent:install` first.');

            return false;
        }

        return true;
    }

    /**
     * Issue a telemetry call (the $send closure returns the AgentResponse) and
     * render the outcome. Returns the process exit code.
     *
     * @param  callable():AgentResponse  $send
     */
    protected function sendTelemetry(callable $send, string $label): int
    {
        try {
            $result = $send();
        } catch (AgentUpgradeRequiredException $e) {
            $this->components->error('Platform Agent upgrade required: '.$e->getMessage());
            $this->line('  Upgrade the package and re-run; '.strtolower($label).' is blocked until you do.');

            return self::FAILURE;
        } catch (ConnectionException $e) {
            $this->components->error('Could not reach the Cloud Hub at '.config('platform-agent.url').': '.$e->getMessage());

            return self::FAILURE;
        } catch (MissingCredentialException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($result->failed()) {
            $this->components->error($label.' rejected ('.$result->status.'): '.($result->message ?? 'unknown error'));

            foreach (($result->errors ?? []) as $field => $messages) {
                foreach ((array) $messages as $message) {
                    $this->line("  - {$field}: {$message}");
                }
            }

            return self::FAILURE;
        }

        if ($result->hasVersionWarning()) {
            $this->components->warn('Version notice ('.strtolower($label).' still accepted): '.$result->versionWarning);
        }

        $this->components->info($label.' delivered.');

        return self::SUCCESS;
    }
}
