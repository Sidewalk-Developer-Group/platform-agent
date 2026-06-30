<?php

declare(strict_types=1);

namespace SidewalkDevelopers\PlatformAgent\Console;

use Illuminate\Http\Client\ConnectionException;
use SidewalkDevelopers\PlatformAgent\Credentials\CredentialStore;
use SidewalkDevelopers\PlatformAgent\Exceptions\AgentUpgradeRequiredException;
use SidewalkDevelopers\PlatformAgent\Exceptions\MissingCredentialException;
use SidewalkDevelopers\PlatformAgent\Http\AgentResponse;
use SidewalkDevelopers\PlatformAgent\Http\PlatformClient;

/**
 * `platform-agent:install` — near-zero-code onboarding (PA1).
 *
 * Publishes config, validates the 3 env vars, performs the enrollment -> runtime
 * PAT exchange via POST /api/v1/agent/register, persists the runtime PAT
 * ENCRYPTED in the customer DB (never `.env`), prints a schedule-wiring hint, and
 * fails loudly on connectivity/auth/upgrade errors (ADR-0007 §2.3 / Addendum D).
 */
final class InstallCommand extends AbstractAgentCommand
{
    protected $signature = 'platform-agent:install
        {--force : Overwrite an existing published config}';

    protected $description = 'Onboard this application to the Cloud Hub (publish config, enroll, persist runtime token, wire schedule).';

    protected string $implementedInPhase = 'PA1';

    public function handle(PlatformClient $client, CredentialStore $credentials): int
    {
        $this->components->info('Onboarding this application to the Cloud Hub.');

        $this->publishConfig();

        if (! $this->validateEnvironment($credentials)) {
            return self::FAILURE;
        }

        if ($credentials->hasRuntimeToken()) {
            $this->components->warn(
                'A runtime token already exists. Re-registering rotates it; a FRESH operator-minted '
                .'enrollment token is required (the previous one was consumed on first install).'
            );
        }

        $result = $this->enroll($client);

        if ($result === null) {
            return self::FAILURE;
        }

        return $this->persistRuntimeToken($result, $credentials);
    }

    private function publishConfig(): void
    {
        try {
            $this->callSilent('vendor:publish', [
                '--tag' => 'platform-agent-config',
                '--force' => (bool) $this->option('force'),
            ]);
            $this->components->task('Published config/platform-agent.php');
        } catch (\Throwable $e) {
            // Non-fatal: onboarding can proceed with the merged package default.
            $this->components->warn('Could not publish config (continuing with package defaults): '.$e->getMessage());
        }
    }

    private function validateEnvironment(CredentialStore $credentials): bool
    {
        $ok = true;

        if (blank(config('platform-agent.url'))) {
            $this->components->error('PLATFORM_URL is not set. Set the Cloud Hub base URL in your .env.');
            $ok = false;
        }

        if (blank($credentials->enrollmentToken())) {
            $this->components->error('PLATFORM_TOKEN is not set. Paste the one-time enrollment token from your operator.');
            $ok = false;
        }

        if (blank(config('platform-agent.application_uuid'))) {
            $this->components->error('PLATFORM_APPLICATION_UUID is not set. Set the bound Application UUID in your .env.');
            $ok = false;
        }

        return $ok;
    }

    private function enroll(PlatformClient $client): ?AgentResponse
    {
        try {
            $result = $client->register($this->registerPayload());
        } catch (AgentUpgradeRequiredException $e) {
            $this->components->error('Platform Agent upgrade required: '.$e->getMessage());
            $this->line('  Upgrade the package (composer update sidewalkdevelopers/platform-agent) and re-run install.');

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
            $this->components->warn('Version notice (backups continue): '.$result->versionWarning);
        }

        $this->verifyApplicationMatch($result);

        return $result;
    }

    private function persistRuntimeToken(AgentResponse $result, CredentialStore $credentials): int
    {
        $runtime = $result->runtimeToken();

        if ($runtime === null || blank($runtime['token'] ?? null)) {
            $this->components->error(
                'The Hub did not return a runtime token. Ensure the Hub Agent Contract is v1.2.0+ (enrollment-exchange).'
            );

            return self::FAILURE;
        }

        $credentials->putRuntimeToken((string) $runtime['token'], [
            'token_id' => $runtime['token_id'] ?? null,
            'abilities' => $runtime['abilities'] ?? [],
            'expires_at' => $runtime['expires_at'] ?? null,
            'application_uuid' => config('platform-agent.application_uuid'),
        ]);

        $this->components->task('Stored runtime token (encrypted) in the application database');
        $this->components->info('Onboarding complete. This application is now paired with the Cloud Hub.');

        $this->printScheduleHint();
        $this->printDiscardEnrollmentHint();

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function registerPayload(): array
    {
        $hostname = gethostname() ?: 'unknown-host';

        return [
            'agent_version' => (string) config('platform-agent.agent_version'),
            'hostname' => $hostname,
            'fingerprint' => $this->fingerprint($hostname),
            'metadata' => [
                'os' => trim(php_uname('s').' '.php_uname('r')),
                'php' => PHP_VERSION,
            ],
        ];
    }

    private function fingerprint(string $hostname): string
    {
        // Stable per-install pairing key so a re-install updates the same
        // agent_registration row (idempotency on (application_id, fingerprint)).
        $seed = implode('|', [
            (string) config('platform-agent.application_uuid'),
            $hostname,
            base_path(),
        ]);

        return 'sha256:'.hash('sha256', $seed);
    }

    private function verifyApplicationMatch(AgentResponse $result): void
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

    private function printScheduleHint(): void
    {
        $this->newLine();
        $this->line('  Next: schedule the agent (heartbeat + split backups) in routes/console.php.');
        $this->line('  A one-line PlatformAgent::schedule($schedule) macro ships with PA2; until then wire:');
        $this->line('    Schedule::command(\'platform-agent:heartbeat\')->everyFiveMinutes();');
        $this->line('    Schedule::command(\'platform-agent:backup --kind=database\')->cron(config(\'platform-agent.backup.kinds.database.cadence\'));');
        $this->line('    Schedule::command(\'platform-agent:backup --kind=files\')->cron(config(\'platform-agent.backup.kinds.files.cadence\'));');
    }

    private function printDiscardEnrollmentHint(): void
    {
        $this->newLine();
        $this->line('  The PLATFORM_TOKEN enrollment token has now been consumed and can be removed from .env.');
        $this->line('  The durable runtime token is stored encrypted in your database (never in .env).');
    }
}
