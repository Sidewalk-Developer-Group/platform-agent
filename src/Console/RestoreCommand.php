<?php

declare(strict_types=1);

namespace SidewalkDevelopers\PlatformAgent\Console;

use Illuminate\Http\Client\ConnectionException;
use SidewalkDevelopers\PlatformAgent\Console\Concerns\ReportsAgentTelemetry;
use SidewalkDevelopers\PlatformAgent\Credentials\CredentialStore;
use SidewalkDevelopers\PlatformAgent\Exceptions\AgentUpgradeRequiredException;
use SidewalkDevelopers\PlatformAgent\Http\PlatformClient;
use SidewalkDevelopers\PlatformAgent\Restore\ArchiveRestorer;
use SidewalkDevelopers\PlatformAgent\Restore\RestoreResult;

/**
 * `platform-agent:restore {location}` — agent-PULL restore (PA4 / ADR-0011).
 *
 * The Hub NEVER pushes into customer infra (ADR-0002 A2). The agent discovers an
 * approved RestoreJob — by the `GET /agent/restore-jobs` poll fallback (Rule 6;
 * the Reverb push subscriber is a later latency optimization) — fetches the
 * NON-MUTATING manifest, pulls the archive bytes off the signed egress URL,
 * VERIFIES the SHA256 (Rule 4) and DEPOSITS the verified `backup.zip` + a
 * `.sha256` sidecar at {location}. It is NON-DESTRUCTIVE: the customer applies
 * the deposited archive; the agent never extracts or imports it.
 *
 * A checksum mismatch aborts: the partial download is deleted and the failure is
 * reported back (no silent failure). The POST /report outcome is authoritative.
 */
final class RestoreCommand extends AbstractAgentCommand
{
    use ReportsAgentTelemetry;

    protected $signature = 'platform-agent:restore
        {location? : Target directory or file path to deposit the verified archive into}
        {--job= : The restore job id to pull (defaults to the single approved job)}';

    protected $description = 'Pull, verify (SHA256) and deposit an approved backup archive from the Cloud Hub.';

    protected string $implementedInPhase = 'PA4';

    /**
     * Whether the most recent {@see selectJob()} returning null was a fatal
     * condition (ambiguous / not found) versus a benign "nothing to do".
     */
    private bool $lastSelectionFatal = false;

    public function handle(
        CredentialStore $credentials,
        PlatformClient $client,
        ArchiveRestorer $restorer,
    ): int {
        if (! $this->requireRuntimeToken($credentials)) {
            return self::FAILURE;
        }

        $location = (string) ($this->argument('location')
            ?? config('platform-agent.restore.default_location', ''));

        if (trim($location) === '') {
            $this->components->error(
                'No restore target. Pass a {location} (directory or file path) or set PLATFORM_RESTORE_LOCATION.'
            );

            return self::FAILURE;
        }

        // Discover approved jobs (poll fallback — Rule 6).
        try {
            $discovery = $client->restoreJobs();
        } catch (AgentUpgradeRequiredException $e) {
            $this->components->error('Platform Agent upgrade required: '.$e->getMessage());

            return self::FAILURE;
        } catch (ConnectionException $e) {
            $this->components->error('Could not reach the Cloud Hub: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($discovery->failed()) {
            $this->components->error('Could not list restore jobs ('.$discovery->status.'): '.($discovery->message ?? 'unknown error'));

            return self::FAILURE;
        }

        /** @var array<int, array<string, mixed>> $jobs */
        $jobs = (array) $discovery->get('restore_jobs', []);

        $jobId = $this->selectJob($jobs);
        if ($jobId === null) {
            return $this->lastSelectionFatal ? self::FAILURE : self::SUCCESS;
        }

        $this->components->info('Pulling restore job '.$jobId.' → '.$location);

        // Pull → verify (Rule 4) → deposit. A 426 hard-block surfaces here.
        try {
            $result = $restorer->pull($jobId, $location);
        } catch (AgentUpgradeRequiredException $e) {
            $this->components->error('Platform Agent upgrade required: '.$e->getMessage());

            return self::FAILURE;
        } catch (ConnectionException $e) {
            $this->components->error('Could not reach the Cloud Hub during download: '.$e->getMessage());

            return self::FAILURE;
        }

        return $this->reportOutcome($client, $jobId, $result);
    }

    /**
     * Choose the job to pull: an explicit --job, else the single approved job.
     * Returns null when there is nothing to do (benign) or the selection is
     * ambiguous / not found (fatal — flagged via {@see $lastSelectionFatal}).
     *
     * @param  array<int, array<string, mixed>>  $jobs
     */
    private function selectJob(array $jobs): ?string
    {
        $this->lastSelectionFatal = false;

        $requested = $this->option('job');
        if (is_string($requested) && $requested !== '') {
            foreach ($jobs as $job) {
                if (($job['id'] ?? null) === $requested) {
                    return $requested;
                }
            }

            $this->components->error('Restore job '.$requested.' is not available to this agent.');
            $this->lastSelectionFatal = true;

            return null;
        }

        if ($jobs === []) {
            $this->components->info('No approved restore jobs to pull.');

            return null;
        }

        if (count($jobs) > 1) {
            $this->components->error('Multiple approved restore jobs — re-run with --job=<id>:');
            foreach ($jobs as $job) {
                $this->line('  - '.($job['id'] ?? '?').' ('.($job['status'] ?? '?').')');
            }
            $this->lastSelectionFatal = true;

            return null;
        }

        return (string) ($jobs[0]['id'] ?? '');
    }

    /**
     * Report the AUTHORITATIVE outcome to the Hub and render the result. A Hub
     * outage on the report is surfaced (warn) but does not undo a verified
     * deposit; the exit code still reflects the real restore outcome.
     */
    private function reportOutcome(PlatformClient $client, string $jobId, RestoreResult $result): int
    {
        $payload = $result->ok
            ? [
                'success' => true,
                'log' => 'Pulled, verified SHA256 ('.$result->actualSha256.') and deposited at '.$result->depositedPath.'.',
            ]
            : [
                'success' => false,
                'reason' => $result->reason ?? 'Restore failed.',
            ];

        try {
            $report = $client->reportRestore($jobId, $payload);
            if ($report->failed()) {
                $this->components->warn('Restore outcome report was rejected ('.$report->status.'): '.($report->message ?? 'unknown').'.');
            }
        } catch (AgentUpgradeRequiredException|ConnectionException $e) {
            $this->components->warn('Could not deliver the restore outcome report: '.$e->getMessage());
        }

        if ($result->ok) {
            $this->components->info('Restore archive verified + deposited at '.$result->depositedPath.' ('.$result->sizeBytes.' bytes).');

            return self::SUCCESS;
        }

        if ($result->checksumMismatch) {
            $this->components->error('CHECKSUM MISMATCH (Rule 4) — restore aborted: '.$result->reason);
        } else {
            $this->components->error('Restore failed: '.$result->reason);
        }

        return self::FAILURE;
    }
}
