<?php

declare(strict_types=1);

namespace SidewalkDevelopers\PlatformAgent\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use SidewalkDevelopers\PlatformAgent\Credentials\CredentialStore;
use SidewalkDevelopers\PlatformAgent\Credentials\DatabaseCredentialStore;
use SidewalkDevelopers\PlatformAgent\Exceptions\AgentUpgradeRequiredException;
use SidewalkDevelopers\PlatformAgent\Exceptions\MissingCredentialException;
use SidewalkDevelopers\PlatformAgent\Http\PlatformClient;
use SidewalkDevelopers\PlatformAgent\Reporting\EnvironmentReporter;
use SidewalkDevelopers\PlatformAgent\State\AgentStateStore;
use SidewalkDevelopers\PlatformAgent\Telemetry\TelemetryCollector;

/**
 * `platform-agent:diagnose` — full pre-flight doctor (PA1, doctor-grade v1.1.0).
 *
 * Prints resolved config (tokens REDACTED — no secrets in output, ADR-0007
 * §2.4), then runs PASS/WARN/FAIL checks that answer "did onboarding actually
 * work?":
 *
 *  - Hub connectivity probe.
 *  - LIVE version verdict — fires a real heartbeat and surfaces the Hub's soft
 *    `version_warning` (WARN) or hard 426 upgrade block (FAIL).
 *  - Schedule wiring — detects whether `PlatformAgent::schedule()` registered
 *    the agent's entries (a missed one-liner means ZERO backups run).
 *  - Scheduler freshness — the scheduled heartbeat stamps a marker; stale
 *    (> 2× the 5-minute beat) means the customer's `schedule:run` cron is dead.
 *  - Temp-disk writability (`backup.temp_disk`).
 *  - spatie/laravel-backup config presence + per-kind sources.
 *  - Local state store readiness (v1.1.0 upgrade migration).
 *
 * Exits non-zero when any check FAILS.
 */
final class DiagnoseCommand extends AbstractAgentCommand
{
    /**
     * Scheduler-freshness grace: 2× the fixed 5-minute heartbeat beat (Rule 2).
     */
    private const FRESHNESS_GRACE_SECONDS = 600;

    protected $signature = 'platform-agent:diagnose';

    protected $description = 'Doctor-grade pre-flight: config, connectivity, live version verdict, schedule wiring, disks and spatie config.';

    protected string $implementedInPhase = 'PA1';

    private int $failed = 0;

    private int $warned = 0;

    public function handle(
        PlatformClient $client,
        CredentialStore $credentials,
        EnvironmentReporter $env,
        TelemetryCollector $telemetry,
        AgentStateStore $state,
        HttpFactory $http,
    ): int {
        $this->components->info('Platform Agent diagnostics');

        $this->printResolvedConfig($client, $credentials);

        $this->newLine();
        $this->components->info('Doctor checks');

        $this->checkConfigPresence();
        $this->checkConnectivity($http);
        $this->checkLiveVersionVerdict($client, $credentials, $env, $telemetry);
        $scheduleWired = $this->checkScheduleWired();
        $this->checkSchedulerFreshness($state, $scheduleWired);
        $this->checkTempDiskWritable();
        $this->checkSpatieConfig();
        $this->checkStateStore($state);

        $this->newLine();
        $summary = sprintf('%d warning(s), %d failure(s).', $this->warned, $this->failed);

        if ($this->failed > 0) {
            $this->components->error('Diagnose FAILED — '.$summary);

            return self::FAILURE;
        }

        $this->components->info($this->warned > 0 ? 'Diagnose passed with '.$summary : 'All checks passed.');

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------
    // Resolved config (redacted) — the original PA1 surface, kept intact.
    // ------------------------------------------------------------------

    private function printResolvedConfig(PlatformClient $client, CredentialStore $credentials): void
    {
        $this->components->twoColumnDetail('Hub URL', (string) (config('platform-agent.url') ?: '<not set>'));
        $this->components->twoColumnDetail('API base', $client->baseUrl());
        $this->components->twoColumnDetail('Application UUID', (string) (config('platform-agent.application_uuid') ?: '<not set>'));
        $this->components->twoColumnDetail('Agent version', $client->agentVersion());
        $this->components->twoColumnDetail('Min Hub contract', (string) config('platform-agent.compatibility.min_hub_contract_version'));

        $enrollment = $credentials->enrollmentToken();
        $this->components->twoColumnDetail(
            'Enrollment token (PLATFORM_TOKEN)',
            $enrollment ? $this->redact($enrollment) : '<not set>',
        );

        $hasRuntime = $credentials->hasRuntimeToken();
        $this->components->twoColumnDetail(
            'Runtime token',
            $hasRuntime ? '<present, encrypted at rest>' : '<absent — run platform-agent:install>',
        );

        if ($hasRuntime) {
            $this->printRuntimeMeta($credentials);
        }
    }

    private function printRuntimeMeta(CredentialStore $credentials): void
    {
        if (! $credentials instanceof DatabaseCredentialStore) {
            return;
        }

        $meta = $credentials->runtimeMeta();

        if (isset($meta['token_id'])) {
            $this->components->twoColumnDetail('  token_id', (string) $meta['token_id']);
        }

        if (isset($meta['abilities']) && is_array($meta['abilities'])) {
            $this->components->twoColumnDetail('  abilities', implode(', ', $meta['abilities']));
        }

        $this->components->twoColumnDetail('  expires_at', (string) ($meta['expires_at'] ?? 'never'));
    }

    // ------------------------------------------------------------------
    // Checks
    // ------------------------------------------------------------------

    private function checkConfigPresence(): void
    {
        $missing = array_keys(array_filter([
            'PLATFORM_URL' => blank(config('platform-agent.url')),
            'PLATFORM_APPLICATION_UUID' => blank(config('platform-agent.application_uuid')),
        ]));

        if ($missing !== []) {
            $this->checkFail('Configuration', 'Missing: '.implode(', ', $missing).'. Set them in your .env.');

            return;
        }

        $this->checkPass('Configuration');
    }

    private function checkConnectivity(HttpFactory $http): void
    {
        $url = (string) config('platform-agent.url');

        if ($url === '') {
            $this->checkWarn('Hub connectivity', 'Skipped — no PLATFORM_URL.');

            return;
        }

        try {
            $response = $http->timeout((int) config('platform-agent.http.connect_timeout', 10))
                ->get(rtrim($url, '/'));

            $this->checkPass('Hub connectivity', 'reachable (HTTP '.$response->status().')');
        } catch (\Throwable $e) {
            $this->checkFail('Hub connectivity', 'UNREACHABLE — '.$e->getMessage());
        }
    }

    /**
     * Fire a REAL heartbeat so the Hub's authoritative version verdict (soft
     * version_warning vs hard 426) is surfaced here, not only in cron logs
     * customers never read. Counts as a genuine liveness signal Hub-side.
     */
    private function checkLiveVersionVerdict(
        PlatformClient $client,
        CredentialStore $credentials,
        EnvironmentReporter $env,
        TelemetryCollector $telemetry,
    ): void {
        if (! $credentials->hasRuntimeToken()) {
            $this->checkWarn('Live version verdict', 'Skipped — not enrolled yet (run `php artisan platform-agent:install`).');

            return;
        }

        try {
            $result = $client->heartbeat($env->snapshot(
                $telemetry->status(),
                array_merge(['trigger' => 'diagnose'], $telemetry->metadata()),
                $telemetry->extra(),
            ));
        } catch (AgentUpgradeRequiredException $e) {
            $this->checkFail('Live version verdict', 'HARD-BLOCKED (HTTP 426): '.$e->getMessage());

            return;
        } catch (ConnectionException $e) {
            $this->checkFail('Live version verdict', 'Could not reach the Hub: '.$e->getMessage());

            return;
        } catch (MissingCredentialException $e) {
            $this->checkWarn('Live version verdict', $e->getMessage());

            return;
        }

        if ($result->failed()) {
            $this->checkFail('Live version verdict', 'Heartbeat rejected (HTTP '.$result->status.'): '.($result->message ?? 'unknown error'));

            return;
        }

        if ($result->hasVersionWarning()) {
            $this->checkWarn('Live version verdict', 'Soft version lag (still accepted): '.$result->versionWarning);

            return;
        }

        $this->checkPass('Live version verdict', 'agent '.$client->agentVersion().' accepted by the Hub');
    }

    /**
     * Detect whether `PlatformAgent::schedule()` registered the agent entries.
     * A missed one-liner is the silent "zero backups ever run" failure mode.
     */
    private function checkScheduleWired(): bool
    {
        try {
            $events = $this->laravel->make(Schedule::class)->events();
        } catch (\Throwable $e) {
            $this->checkWarn('Schedule wiring', 'Could not inspect the schedule: '.$e->getMessage());

            return false;
        }

        $agentEntries = collect($events)
            ->filter(fn ($event) => str_contains((string) $event->command, 'platform-agent:'));

        if ($agentEntries->isEmpty()) {
            $this->checkFail(
                'Schedule wiring',
                'NO platform-agent schedule entries found — heartbeats and BACKUPS WILL NOT RUN. '
                .'Add to routes/console.php: \SidewalkDevelopers\PlatformAgent\PlatformAgent::schedule(app(\Illuminate\Console\Scheduling\Schedule::class));',
            );

            return false;
        }

        $this->checkPass('Schedule wiring', $agentEntries->count().' platform-agent entries registered');

        return true;
    }

    /**
     * The scheduled heartbeat stamps a marker every 5 minutes; a stale marker
     * means the schedule is wired in code but the customer's `schedule:run`
     * cron/daemon is not actually executing.
     */
    private function checkSchedulerFreshness(AgentStateStore $state, bool $scheduleWired): void
    {
        if (! $scheduleWired) {
            $this->checkWarn('Scheduler freshness', 'Skipped — schedule not wired.');

            return;
        }

        $lastRan = $state->lastScheduledHeartbeatAt();

        if ($lastRan === null) {
            $this->checkWarn(
                'Scheduler freshness',
                'No scheduled heartbeat recorded yet. Ensure the cron `* * * * * php artisan schedule:run` '
                .'(or a schedule:work daemon) is running, then re-check after ~5 minutes.',
            );

            return;
        }

        $ageSeconds = (int) abs(now()->getTimestamp() - $lastRan->getTimestamp());

        if ($ageSeconds > self::FRESHNESS_GRACE_SECONDS) {
            $this->checkWarn(
                'Scheduler freshness',
                'Last scheduled heartbeat was '.$lastRan->diffForHumans()
                .' — the `schedule:run` cron appears DEAD. Backups will not run until it does.',
            );

            return;
        }

        $this->checkPass('Scheduler freshness', 'scheduled heartbeat ran '.$lastRan->diffForHumans());
    }

    private function checkTempDiskWritable(): void
    {
        $disk = (string) config('platform-agent.backup.temp_disk', 'local');
        $probe = '.platform-agent-diagnose-'.Str::random(8);

        try {
            $filesystem = Storage::disk($disk);

            if (! $filesystem->put($probe, 'probe')) {
                $this->checkFail('Temp disk', 'Disk "'.$disk.'" refused the write probe.');

                return;
            }

            $readable = $filesystem->get($probe) === 'probe';
            $filesystem->delete($probe);

            $readable
                ? $this->checkPass('Temp disk', '"'.$disk.'" is writable')
                : $this->checkFail('Temp disk', 'Disk "'.$disk.'" wrote but could not read back the probe.');
        } catch (\Throwable $e) {
            $this->checkFail('Temp disk', 'Disk "'.$disk.'" is not usable: '.$e->getMessage());
        }
    }

    /**
     * Backups run through spatie/laravel-backup — its config must exist, and a
     * kind whose source list is empty will fail every scheduled run.
     */
    private function checkSpatieConfig(): void
    {
        if (! is_array(config('backup.backup'))) {
            $this->checkFail(
                'spatie config',
                'config("backup.backup") is missing — is spatie/laravel-backup installed and its config loaded?',
            );

            return;
        }

        $issues = [];

        $databases = config('backup.backup.source.databases');
        if (! is_array($databases) || $databases === []) {
            $issues[] = 'no source databases (backup.source.databases) — scheduled DATABASE backups will fail';
        }

        $include = config('backup.backup.source.files.include');
        if (! is_array($include) || $include === []) {
            $issues[] = 'no source file paths (backup.source.files.include) — scheduled FILES backups will fail';
        }

        if ($issues !== []) {
            $this->checkWarn('spatie config', ucfirst(implode('; ', $issues)).'.');

            return;
        }

        $this->checkPass('spatie config', 'sources configured for both kinds');
    }

    private function checkStateStore(AgentStateStore $state): void
    {
        if ($state->isReady()) {
            $this->checkPass('Local state store');

            return;
        }

        $this->checkWarn(
            'Local state store',
            'The platform_agent_state table is missing — run `php artisan migrate` '
            .'(until then telemetry omits last_backup_at / computed status).',
        );
    }

    // ------------------------------------------------------------------
    // Rendering
    // ------------------------------------------------------------------

    private function checkPass(string $check, string $detail = ''): void
    {
        $this->components->twoColumnDetail(
            $check.($detail !== '' ? ' — '.$detail : ''),
            '<fg=green;options=bold>PASS</>',
        );
    }

    private function checkWarn(string $check, string $detail): void
    {
        $this->warned++;
        $this->components->twoColumnDetail($check, '<fg=yellow;options=bold>WARN</>');
        $this->line('    <fg=yellow>'.$detail.'</>');
    }

    private function checkFail(string $check, string $detail): void
    {
        $this->failed++;
        $this->components->twoColumnDetail($check, '<fg=red;options=bold>FAIL</>');
        $this->line('    <fg=red>'.$detail.'</>');
    }

    /**
     * Redact a Sanctum-style "{id}|{secret}" token: keep the non-secret id
     * segment, mask the secret. Never print the secret half.
     */
    private function redact(string $token): string
    {
        if (str_contains($token, '|')) {
            [$id] = explode('|', $token, 2);

            return $id.'|'.str_repeat('*', 8);
        }

        return str_repeat('*', 8);
    }
}
