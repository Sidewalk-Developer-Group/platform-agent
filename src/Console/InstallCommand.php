<?php

declare(strict_types=1);

namespace SidewalkDevelopers\PlatformAgent\Console;

use SidewalkDevelopers\PlatformAgent\Console\Concerns\RunsEnrollmentExchange;
use SidewalkDevelopers\PlatformAgent\Credentials\CredentialStore;
use SidewalkDevelopers\PlatformAgent\Http\PlatformClient;
use SidewalkDevelopers\PlatformAgent\Reporting\EnvironmentReporter;
use SidewalkDevelopers\PlatformAgent\State\AgentStateStore;

/**
 * `platform-agent:install` — near-zero-code onboarding (PA1).
 *
 * Publishes config, validates the 3 env vars, performs the enrollment -> runtime
 * PAT exchange via POST /api/v1/agent/register, persists the runtime PAT
 * ENCRYPTED in the customer DB (never `.env`), WIRES the agent schedule into
 * routes/console.php (v1.1.0 — interactive confirm defaulting to yes;
 * `--schedule` / `--no-schedule` for non-interactive runs; idempotent, never
 * duplicated), and fails loudly on connectivity/auth/upgrade errors
 * (ADR-0007 §2.3 / Addendum D). Skipping the wiring prints a LOUD warning —
 * an unwired schedule means ZERO backups ever run.
 *
 * The enrollment exchange itself is shared with `platform-agent:register` via
 * {@see RunsEnrollmentExchange}; the wire payload comes from the shared
 * {@see EnvironmentReporter} (PA2).
 */
final class InstallCommand extends AbstractAgentCommand
{
    use RunsEnrollmentExchange;

    /**
     * Appended to routes/console.php. Fully-qualified on purpose — no `use`
     * statements needed mid-file; `PlatformAgent::schedule` doubles as the
     * idempotency marker.
     */
    private const SCHEDULE_SNIPPET = <<<'PHP'

// Platform Agent — heartbeat, telemetry report, split backups, local retention
// and the restore poll (added by platform-agent:install).
\SidewalkDevelopers\PlatformAgent\PlatformAgent::schedule(app(\Illuminate\Console\Scheduling\Schedule::class));

PHP;

    protected $signature = 'platform-agent:install
        {--force : Overwrite an existing published config}
        {--schedule : Wire the agent schedule into routes/console.php without prompting}
        {--no-schedule : Skip schedule wiring (you must wire it yourself)}';

    protected $description = 'Onboard this application to the Cloud Hub (publish config, enroll, persist runtime token, wire schedule).';

    protected string $implementedInPhase = 'PA1';

    public function handle(PlatformClient $client, CredentialStore $credentials, EnvironmentReporter $env, AgentStateStore $state): int
    {
        $this->components->info('Onboarding this application to the Cloud Hub.');

        $this->publishConfig();

        if (! $this->validateEnvironment($credentials)) {
            return self::FAILURE;
        }

        // Pre-flight: the enrollment token is single-use and is consumed by the
        // exchange below. Ensure the store can persist the result FIRST, so a
        // missing table never burns the token (v1.0.3).
        if (! $this->ensureStorageReady($credentials, $state)) {
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

        $this->wireSchedule();
        $this->printDiscardEnrollmentHint();

        return self::SUCCESS;
    }

    /**
     * Auto-wire `PlatformAgent::schedule(...)` into routes/console.php (v1.1.0)
     * — closing the silent "install succeeded but zero backups ever run"
     * failure mode. Idempotent: an existing registration is detected and never
     * duplicated. Skipping (decline / --no-schedule / missing file) warns LOUD.
     */
    private function wireSchedule(): void
    {
        $consolePath = $this->laravel->basePath('routes/console.php');

        if (is_file($consolePath) && str_contains((string) file_get_contents($consolePath), 'PlatformAgent::schedule')) {
            $this->components->task('Agent schedule already wired in routes/console.php');

            return;
        }

        if (! is_file($consolePath)) {
            $this->warnScheduleNotWired('routes/console.php was not found, so it could not be wired automatically.');

            return;
        }

        if (! $this->shouldWireSchedule()) {
            $this->warnScheduleNotWired('Schedule wiring was skipped.');

            return;
        }

        if (@file_put_contents($consolePath, self::SCHEDULE_SNIPPET, FILE_APPEND) === false) {
            $this->warnScheduleNotWired('routes/console.php is not writable.');

            return;
        }

        $this->components->task('Wired PlatformAgent::schedule(...) into routes/console.php');
        $this->line('  Ensure the scheduler itself runs: `* * * * * php artisan schedule:run` (cron) or a schedule:work daemon.');
    }

    private function shouldWireSchedule(): bool
    {
        if ((bool) $this->option('no-schedule')) {
            return false;
        }

        if ((bool) $this->option('schedule')) {
            return true;
        }

        // Interactive default YES; non-interactive runs take the default too.
        return $this->confirm('Wire the agent schedule (heartbeat, reports, backups, retention) into routes/console.php now?', true);
    }

    private function warnScheduleNotWired(string $reason): void
    {
        $this->newLine();
        $this->components->error(
            'NO BACKUPS WILL RUN — the agent schedule is NOT wired. '.$reason
        );
        $this->printScheduleHint();
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
     * BEFORE the single-use enrollment exchange. If a package table is
     * missing we run the package's OWN migrations (never a blanket `migrate`,
     * which would touch the customer's unrelated pending migrations) and
     * re-check. Failing here — rather than after the exchange — keeps the
     * one-time enrollment token intact for a clean retry (v1.0.3).
     *
     * The non-secret state table (v1.1.0) is prepared on the same pass but is
     * NOT a hard requirement — telemetry is null-safe without it.
     */
    private function ensureStorageReady(CredentialStore $credentials, AgentStateStore $state): bool
    {
        if ($credentials->isReady() && $state->isReady()) {
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

        if (! $state->isReady()) {
            // Non-fatal: telemetry degrades null-safely without local state.
            $this->components->warn(
                'The platform_agent_state table is missing (telemetry will omit last_backup_at / '
                .'computed status). Run `php artisan migrate` to create it.'
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
        $this->line('  Wire the agent schedule in routes/console.php with one line —');
        $this->line('    use SidewalkDevelopers\\PlatformAgent\\PlatformAgent;');
        $this->line('    PlatformAgent::schedule(app(\\Illuminate\\Console\\Scheduling\\Schedule::class));');
        $this->line('  It registers the heartbeat (every 5 min), the hourly report, both split backups');
        $this->line('  and the daily local retention cleans. Verify with `php artisan platform-agent:diagnose`.');
    }

    private function printDiscardEnrollmentHint(): void
    {
        $this->newLine();
        $this->line('  The PLATFORM_TOKEN enrollment token has now been consumed and can be removed from .env.');
        $this->line('  The durable runtime token is stored encrypted in your database (never in .env).');
    }
}
