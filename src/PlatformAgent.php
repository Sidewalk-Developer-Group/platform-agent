<?php

declare(strict_types=1);

namespace SidewalkDevelopers\PlatformAgent;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Arr;

/**
 * One-line schedule wiring for the agent (PA2).
 *
 * Drop this in the customer app's `routes/console.php` (or a service provider):
 *
 *     use Illuminate\Console\Scheduling\Schedule;
 *     use SidewalkDevelopers\PlatformAgent\PlatformAgent;
 *
 *     Schedule::call(fn () => null); // (your other schedule entries)
 *     PlatformAgent::schedule(app(Schedule::class));
 *
 * It wires the four operational entries — a frequent heartbeat (Rule 2: every
 * 5 minutes), a richer hourly report, and the two SPLIT backups (database +
 * files; ADR-0007 Addendum F) on their configured cadences. Backup behavior
 * lands at PA3; scheduling them now keeps onboarding near-zero-code.
 */
final class PlatformAgent
{
    /**
     * The package's own published SemVer — the SINGLE source of truth for the
     * `agent_version` reported on the wire (ADR-0007 §2.9). The config default
     * references THIS constant (never a frozen string literal) so a customer's
     * published `config/platform-agent.php` keeps tracking the installed package
     * across upgrades instead of freezing the value at publish time. The release
     * CI guards `tag == self::VERSION`.
     */
    public const VERSION = '1.0.6';

    /**
     * Register the agent's scheduled entries on the given Schedule.
     *
     * @param  array<string, mixed>|null  $config  resolved `platform-agent` config (defaults to config('platform-agent'))
     */
    public static function schedule(Schedule $schedule, ?array $config = null): void
    {
        $config ??= (array) config('platform-agent', []);

        // Rule 2: every Storage Node / agent reports liveness on a 5-minute beat.
        $schedule->command('platform-agent:heartbeat')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->runInBackground();

        // Richer, less-frequent health/version/environment telemetry.
        $schedule->command('platform-agent:report')
            ->hourly()
            ->withoutOverlapping()
            ->runInBackground();

        // SPLIT backups — DB and files are NEVER combined; each on its own cadence.
        $schedule->command('platform-agent:backup --kind=database')
            ->cron((string) Arr::get($config, 'backup.kinds.database.cadence', '0 */6 * * *'))
            ->withoutOverlapping();

        $schedule->command('platform-agent:backup --kind=files')
            ->cron((string) Arr::get($config, 'backup.kinds.files.cadence', '0 2 * * *'))
            ->withoutOverlapping();

        // Rule 6: the restore poll fallback is ALWAYS wired — independent of the
        // optional `platform-agent:listen` push daemon (PA5). A `--once` sweep
        // drains any approved restore job to the configured deposit location.
        // Only scheduled when a default location exists (the command needs one).
        if ((string) Arr::get($config, 'restore.default_location', '') !== '') {
            $schedule->command('platform-agent:listen --once')
                ->everyFiveMinutes()
                ->withoutOverlapping()
                ->runInBackground();
        }
    }
}
