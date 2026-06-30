<?php

declare(strict_types=1);

namespace SidewalkDevelopers\PlatformAgent\Console;

use SidewalkDevelopers\PlatformAgent\Console\Concerns\ReportsAgentTelemetry;
use SidewalkDevelopers\PlatformAgent\Credentials\CredentialStore;
use SidewalkDevelopers\PlatformAgent\Exceptions\AgentUpgradeRequiredException;
use SidewalkDevelopers\PlatformAgent\Restore\Push\RestorePushSubscriber;
use SidewalkDevelopers\PlatformAgent\Restore\Push\RestoreSignal;
use SidewalkDevelopers\PlatformAgent\Restore\RestoreCoordinator;
use SidewalkDevelopers\PlatformAgent\Restore\RestoreSweepResult;

/**
 * `platform-agent:listen` — restore-discovery push listener (PA5 / ADR-0007
 * Addendum B.5). Holds a long-lived Reverb/Pusher subscription to the Hub's
 * per-Application private restore channel and DRAINS approved restore jobs the
 * instant the Hub broadcasts, instead of waiting for the next poll.
 *
 * Polling is the Rule-6 fallback and is NEVER removed: a poll sweep runs on
 * startup, on every idle tick, and on any disconnect — and the scheduled
 * `platform-agent:restore` poll stays wired regardless of this daemon.
 *
 * `--once` drains a single poll sweep and exits (no subscription) — handy for a
 * scheduler entry or a smoke check. With `restore.push.enabled=false` the
 * command behaves as `--once` (poll-only deployments need no daemon).
 */
final class ListenCommand extends AbstractAgentCommand
{
    use ReportsAgentTelemetry;

    protected $signature = 'platform-agent:listen
        {location? : Target directory to deposit verified archives into (defaults to PLATFORM_RESTORE_LOCATION)}
        {--once : Run a single poll sweep and exit instead of subscribing}';

    protected $description = 'Listen for Hub restore broadcasts and drain approved restore jobs (poll fallback always on).';

    protected string $implementedInPhase = 'PA5';

    public function handle(
        CredentialStore $credentials,
        RestoreCoordinator $coordinator,
        RestorePushSubscriber $subscriber,
    ): int {
        if (! $this->requireRuntimeToken($credentials)) {
            return self::FAILURE;
        }

        $location = (string) ($this->argument('location')
            ?? config('platform-agent.restore.default_location', ''));

        if (trim($location) === '') {
            $this->components->error(
                'No restore target. Pass a {location} directory or set PLATFORM_RESTORE_LOCATION.'
            );

            return self::FAILURE;
        }

        $pushEnabled = (bool) config('platform-agent.restore.push.enabled', false);

        // Startup safety sweep (Rule 6) — drains anything already approved.
        try {
            $this->renderSweep($coordinator->drain($location));
        } catch (AgentUpgradeRequiredException $e) {
            $this->components->error('Platform Agent upgrade required: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('once') || ! $pushEnabled) {
            $this->components->info(
                $pushEnabled
                    ? 'Single poll sweep complete (--once).'
                    : 'Restore push disabled — ran one poll sweep. Enable PLATFORM_RESTORE_PUSH_ENABLED for live drain.'
            );

            return self::SUCCESS;
        }

        $this->components->info('Listening for restore broadcasts → '.$location.' (poll fallback every '
            .(int) config('platform-agent.restore.push.poll_fallback_seconds', 300).'s).');

        try {
            $subscriber->listen(function (RestoreSignal $signal) use ($coordinator, $location): bool {
                $this->renderSweep($coordinator->drain($location), $signal);

                return true; // run until interrupted (SIGINT) or the transport closes.
            });
        } catch (AgentUpgradeRequiredException $e) {
            $this->components->error('Platform Agent upgrade required — stopping listener: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Restore listener stopped.');

        return self::SUCCESS;
    }

    private function renderSweep(RestoreSweepResult $sweep, ?RestoreSignal $signal = null): void
    {
        if ($sweep->discoveryFailed) {
            $this->components->warn('Restore discovery failed: '.$sweep->reason);

            return;
        }

        if ($signal !== null && $signal->isRestore()) {
            $this->line('  <fg=cyan>push</> restore signal'.($signal->jobId ? ' (job '.$signal->jobId.')' : '').' received.');
        }

        if (! $sweep->didWork()) {
            return; // nothing downloadable — stay quiet to keep the daemon log clean.
        }

        $this->components->info(sprintf(
            'Sweep: %d considered, %d deposited, %d failed.',
            $sweep->considered,
            $sweep->deposited,
            $sweep->failed,
        ));
    }
}
