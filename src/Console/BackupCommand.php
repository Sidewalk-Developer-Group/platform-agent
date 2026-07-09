<?php

declare(strict_types=1);

namespace SidewalkDevelopers\PlatformAgent\Console;

use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Str;
use SidewalkDevelopers\PlatformAgent\Backup\ArchiveUploader;
use SidewalkDevelopers\PlatformAgent\Backup\BackupRunner;
use SidewalkDevelopers\PlatformAgent\Console\Concerns\ReportsAgentTelemetry;
use SidewalkDevelopers\PlatformAgent\Credentials\CredentialStore;
use SidewalkDevelopers\PlatformAgent\Exceptions\AgentUpgradeRequiredException;
use SidewalkDevelopers\PlatformAgent\Reporting\BackupRunReporter;
use SidewalkDevelopers\PlatformAgent\State\AgentStateStore;

/**
 * `platform-agent:backup --kind=database|files` — the headline split backup (PA3).
 *
 * One invocation = ONE kind (ADR-0007 Addendum F): spatie `backup:run --only-db`
 * or `--only-files` to a LOCAL temp disk → SHA256 sidecar (Rule 4) → upload the
 * pair (single-POST `/agent/archives` below `threshold_bytes`, else resumable tus
 * `/agent/uploads`) → the Hub recomputes the checksum and returns its verdict.
 * DB and files are NEVER combined; "back up everything" runs both kinds.
 *
 * The run is reported to `/agent/backup-runs` (ADR-0008) on the SAME
 * `agent_run_uuid`: a `running` START, then a terminal `success`/`failed`. Both
 * successes AND failures are reported so the Coverage KPI sees failures; a Hub
 * `corrupted` verdict (Rule 4 mismatch) is a FAILED run. The local temp archive +
 * sidecar are removed afterwards (temp disk only — never a node/Hub).
 */
final class BackupCommand extends AbstractAgentCommand
{
    use ReportsAgentTelemetry;

    private const KINDS = ['database', 'files'];

    private const SUFFIX = ['database' => 'db', 'files' => 'files'];

    protected $signature = 'platform-agent:backup
        {--kind=database : Which split backup to run: database or files}
        {--trigger=scheduled : How the run was initiated: scheduled or manual}';

    protected $description = 'Run a split (database|files) backup, checksum and upload it to the Cloud Hub.';

    protected string $implementedInPhase = 'PA3';

    public function handle(
        CredentialStore $credentials,
        BackupRunner $runner,
        ArchiveUploader $uploader,
        BackupRunReporter $reporter,
        AgentStateStore $state,
    ): int {
        $kind = (string) $this->option('kind');
        if (! in_array($kind, self::KINDS, true)) {
            $this->components->error('Invalid --kind "'.$kind.'". Use one of: '.implode(', ', self::KINDS).'.');

            return self::FAILURE;
        }

        $trigger = (string) $this->option('trigger');
        if (! in_array($trigger, ['scheduled', 'manual'], true)) {
            $trigger = 'scheduled';
        }

        if (! $this->requireRuntimeToken($credentials)) {
            return self::FAILURE;
        }

        $runUuid = (string) Str::uuid();
        $spatieName = $this->spatieName($kind);
        $startedAt = new DateTimeImmutable();

        // START — best-effort: a transient Hub outage must NOT block the backup,
        // but a 426 hard-block (incompatible agent) aborts before doing any work.
        try {
            $reporter->reportRunning($runUuid, $kind, $spatieName, $startedAt, $trigger);
        } catch (AgentUpgradeRequiredException $e) {
            $this->components->error('Platform Agent upgrade required: '.$e->getMessage());
            $state->recordBackupRun($kind, success: false);

            return self::FAILURE;
        } catch (ConnectionException $e) {
            $this->components->warn('Could not report backup start (continuing): '.$e->getMessage());
        }

        $this->components->info(ucfirst($kind).' backup started ('.$spatieName.').');

        // Run spatie for this kind.
        $result = $runner->run($kind, $spatieName, $this->tempDisk());

        if (! $result->ok) {
            $this->components->error(ucfirst($kind).' backup failed: '.$result->error);
            $this->reportTerminal(fn () => $reporter->reportFailed(
                $runUuid, $kind, $spatieName, $startedAt, new DateTimeImmutable(), (string) $result->error, $trigger,
            ));
            $state->recordBackupRun($kind, success: false);

            return self::FAILURE;
        }

        // Checksum the EXACT bytes we will upload (Rule 4) + write the sidecar.
        $checksum = (string) hash_file('sha256', (string) $result->archivePath);
        $sidecarPath = $this->writeSidecar((string) $result->archivePath, $checksum);

        try {
            $upload = $uploader->upload(
                $kind, (string) $result->archivePath, $sidecarPath, $checksum, $result->sizeBytes, $runUuid, $startedAt,
            );
        } catch (AgentUpgradeRequiredException $e) {
            $this->components->error('Platform Agent upgrade required: '.$e->getMessage());
            $this->reportTerminal(fn () => $reporter->reportFailed(
                $runUuid, $kind, $spatieName, $startedAt, new DateTimeImmutable(), 'Upgrade required: '.$e->getMessage(), $trigger,
            ));
            $state->recordBackupRun($kind, success: false);
            $this->cleanup((string) $result->archivePath, $sidecarPath);

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->components->error('Upload failed: '.$e->getMessage());
            $this->reportTerminal(fn () => $reporter->reportFailed(
                $runUuid, $kind, $spatieName, $startedAt, new DateTimeImmutable(), 'Upload failed: '.$e->getMessage(), $trigger,
            ));
            $state->recordBackupRun($kind, success: false);
            $this->cleanup((string) $result->archivePath, $sidecarPath);

            return self::FAILURE;
        }

        // Rule 4: a Hub corrupted verdict is a FAILED run (no silent success).
        if ($upload->isCorrupted()) {
            $this->components->error('Hub checksum verification FAILED — archive marked corrupted.');
            $this->reportTerminal(fn () => $reporter->reportFailed(
                $runUuid, $kind, $spatieName, $startedAt, new DateTimeImmutable(),
                'Hub checksum verification failed (corrupted); archive '.($upload->archiveId ?? 'unknown'), $trigger,
            ));
            $state->recordBackupRun($kind, success: false);
            $this->cleanup((string) $result->archivePath, $sidecarPath);

            return self::FAILURE;
        }

        $this->reportTerminal(fn () => $reporter->reportSuccess(
            $runUuid, $kind, $spatieName, $startedAt, new DateTimeImmutable(),
            $result->sizeBytes, $checksum, $upload->archiveId, $trigger,
        ));

        $state->recordBackupRun($kind, success: true);
        $this->cleanup((string) $result->archivePath, $sidecarPath);

        $this->components->info(ucfirst($kind).' backup uploaded + verified ('.$upload->via.', '.$result->sizeBytes.' bytes).');

        return self::SUCCESS;
    }

    /**
     * Send a terminal run-log event, tolerating a Hub outage (the backup itself
     * already happened — its outcome must still be attempted, never throw here).
     *
     * @param  callable():mixed  $send
     */
    private function reportTerminal(callable $send): void
    {
        try {
            $send();
        } catch (AgentUpgradeRequiredException|ConnectionException $e) {
            $this->components->warn('Could not deliver backup run-log (will reconcile next run): '.$e->getMessage());
        }
    }

    private function spatieName(string $kind): string
    {
        $configured = config('platform-agent.backup.kinds.'.$kind.'.spatie_name');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return ((string) config('platform-agent.backup.name', 'platform-agent')).'-'.self::SUFFIX[$kind];
    }

    private function tempDisk(): string
    {
        return (string) config('platform-agent.backup.temp_disk', 'local');
    }

    private function writeSidecar(string $archivePath, string $checksum): string
    {
        $sidecarPath = $archivePath.'.sha256';
        file_put_contents($sidecarPath, $checksum.'  '.basename($archivePath).PHP_EOL);

        return $sidecarPath;
    }

    private function cleanup(string ...$paths): void
    {
        foreach ($paths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
}
