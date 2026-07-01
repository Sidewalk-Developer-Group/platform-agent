<?php

declare(strict_types=1);

namespace SidewalkDevelopers\PlatformAgent\Console;

use SidewalkDevelopers\PlatformAgent\Console\Concerns\RunsEnrollmentExchange;
use SidewalkDevelopers\PlatformAgent\Credentials\CredentialStore;
use SidewalkDevelopers\PlatformAgent\Http\PlatformClient;
use SidewalkDevelopers\PlatformAgent\Reporting\EnvironmentReporter;

/**
 * `platform-agent:install` — near-zero-code onboarding (PA1).
 *
 * Publishes config, validates the 3 env vars, performs the enrollment -> runtime
 * PAT exchange via POST /api/v1/agent/register, persists the runtime PAT
 * ENCRYPTED in the customer DB (never `.env`), prints a schedule-wiring hint, and
 * fails loudly on connectivity/auth/upgrade errors (ADR-0007 §2.3 / Addendum D).
 *
 * The enrollment exchange itself is shared with `platform-agent:register` via
 * {@see RunsEnrollmentExchange}; the wire payload comes from the shared
 * {@see EnvironmentReporter} (PA2).
 */
final class InstallCommand extends AbstractAgentCommand
{
    use RunsEnrollmentExchange;

    protected $signature = 'platform-agent:install
        {--force : Overwrite an existing published config}';

    protected $description = 'Onboard this application to the Cloud Hub (publish config, enroll, persist runtime token, wire schedule).';

    protected string $implementedInPhase = 'PA1';

    public function handle(PlatformClient $client, CredentialStore $credentials, EnvironmentReporter $env): int
    {
        $this->components->info('Onboarding this application to the Cloud Hub.');

        $this->publishConfig();

        if (! $this->validateEnvironment($credentials)) {
            return self::FAILURE;
        }

        // Pre-flight: the enrollment token is single-use and is consumed by the
        // exchange below. Ensure the store can persist the result FIRST, so a
        // missing table never burns the token (v1.0.3).
        if (! $this->ensureStorageReady($credentials)) {
            return self::FAILURE;
        }

        if ($credentials->hasRuntimeToken()) {
            $this->components->warn(
                'A runtime token already exists. Re-registering rotates it; a FRESH operator-minted '
                .'enrollment token is required (the previous one was consumed on first install).'
            );
        }

        $result = $this->runEnrollment($client, $env);

        if ($result === null) {
            return self::FAILURE;
        }

        if (! $this->persistRuntimeToken($result, $credentials)) {
            return self::FAILURE;
        }

        $this->components->task('Stored runtime token (encrypted) in the application database');
        $this->components->info('Onboarding complete. This application is now paired with the Cloud Hub.');

        $this->printScheduleHint();
        $this->printDiscardEnrollmentHint();

        return self::SUCCESS;
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

    /**
     * Guarantee the durable credential store can persist the runtime token
     * BEFORE the single-use enrollment exchange. If the package table is
     * missing we run the package's OWN migration (never a blanket `migrate`,
     * which would touch the customer's unrelated pending migrations) and
     * re-check. Failing here — rather than after the exchange — keeps the
     * one-time enrollment token intact for a clean retry (v1.0.3).
     */
    private function ensureStorageReady(CredentialStore $credentials): bool
    {
        if ($credentials->isReady()) {
            return true;
        }

        $migrationsPath = realpath(__DIR__.'/../../database/migrations');

        if ($migrationsPath !== false) {
            $this->components->task(
                'Preparing credential storage (running package migration)',
                function () use ($migrationsPath): void {
                    $this->callSilent('migrate', [
                        '--path' => $migrationsPath,
                        '--realpath' => true,
                        '--force' => true,
                    ]);
                }
            );
        }

        if ($credentials->isReady()) {
            return true;
        }

        $this->components->error(
            'Credential storage is not ready: the platform_agent_credentials table is missing and '
            .'could not be created automatically. Run `php artisan migrate` and retry. No enrollment '
            .'token was consumed.'
        );

        return false;
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

    private function printScheduleHint(): void
    {
        $this->newLine();
        $this->line('  Next: wire the agent schedule in routes/console.php with one line —');
        $this->line('    use SidewalkDevelopers\\PlatformAgent\\PlatformAgent;');
        $this->line('    PlatformAgent::schedule(app(\\Illuminate\\Console\\Scheduling\\Schedule::class));');
        $this->line('  It registers the heartbeat (every 5 min), the hourly report, and both split backups.');
    }

    private function printDiscardEnrollmentHint(): void
    {
        $this->newLine();
        $this->line('  The PLATFORM_TOKEN enrollment token has now been consumed and can be removed from .env.');
        $this->line('  The durable runtime token is stored encrypted in your database (never in .env).');
    }
}
