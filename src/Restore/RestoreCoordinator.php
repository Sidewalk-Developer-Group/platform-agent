<?php

declare(strict_types=1);

namespace SidewalkDevelopers\PlatformAgent\Restore;

use Illuminate\Http\Client\ConnectionException;
use Psr\Log\LoggerInterface;
use SidewalkDevelopers\PlatformAgent\Exceptions\AgentUpgradeRequiredException;
use SidewalkDevelopers\PlatformAgent\Http\PlatformClient;

/**
 * Drains every approved/downloadable restore job to a deposit location (PA5).
 *
 * Shared by the push subscriber (`platform-agent:listen`, drain-on-broadcast)
 * and usable for scheduled sweeps. Each job routes through the SAME authoritative
 * {@see ArchiveRestorer} pull → SHA256 verify (Rule 4) → non-destructive deposit
 * pipeline as the single-job `platform-agent:restore`, then the outcome is
 * reported to the Hub — so a push-driven restore is byte-identical to a polled
 * one and no failure is silent. A 426 hard-block aborts the whole sweep
 * (everything is blocked until the agent is upgraded).
 */
final class RestoreCoordinator
{
    public function __construct(
        private readonly PlatformClient $client,
        private readonly ArchiveRestorer $restorer,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @throws AgentUpgradeRequiredException  a 426 hard-block (caller surfaces it)
     */
    public function drain(string $location): RestoreSweepResult
    {
        try {
            $discovery = $this->client->restoreJobs();
        } catch (ConnectionException $e) {
            return RestoreSweepResult::discoveryFailed('Could not reach the Cloud Hub: '.$e->getMessage());
        }

        if ($discovery->failed()) {
            return RestoreSweepResult::discoveryFailed(
                'Could not list restore jobs ('.$discovery->status.'): '.($discovery->message ?? 'unknown error'),
            );
        }

        /** @var array<int, array<string, mixed>> $jobs */
        $jobs = array_values(array_filter(
            (array) $discovery->get('restore_jobs', []),
            static fn ($job): bool => is_array($job) && (bool) ($job['is_downloadable'] ?? false),
        ));

        $deposited = 0;
        $failed = 0;

        foreach ($jobs as $job) {
            $jobId = (string) ($job['id'] ?? '');
            if ($jobId === '') {
                continue;
            }

            if ($this->pullAndReport($jobId, $location)) {
                $deposited++;
            } else {
                $failed++;
            }
        }

        return new RestoreSweepResult(
            considered: count($jobs),
            deposited: $deposited,
            failed: $failed,
        );
    }

    /**
     * @throws AgentUpgradeRequiredException
     */
    private function pullAndReport(string $jobId, string $location): bool
    {
        try {
            $result = $this->restorer->pull($jobId, $location);
        } catch (ConnectionException $e) {
            // A transient network fault on one job — report what we can and move
            // on; the next sweep (push or poll) retries. Never silent.
            $this->report($jobId, RestoreResult::failed('Download connection failed: '.$e->getMessage()));

            return false;
        }

        $this->report($jobId, $result);

        return $result->ok;
    }

    private function report(string $jobId, RestoreResult $result): void
    {
        $payload = $result->ok
            ? ['success' => true, 'log' => 'Pulled, verified SHA256 ('.$result->actualSha256.') and deposited at '.$result->depositedPath.'.']
            : ['success' => false, 'reason' => $result->reason ?? 'Restore failed.'];

        try {
            $report = $this->client->reportRestore($jobId, $payload);
            if ($report->failed()) {
                $this->logger?->warning('platform-agent.restore.report_rejected', [
                    'restore_job_id' => $jobId,
                    'status' => $report->status,
                ]);
            }
        } catch (AgentUpgradeRequiredException $e) {
            throw $e;
        } catch (ConnectionException $e) {
            $this->logger?->warning('platform-agent.restore.report_undelivered', [
                'restore_job_id' => $jobId,
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
