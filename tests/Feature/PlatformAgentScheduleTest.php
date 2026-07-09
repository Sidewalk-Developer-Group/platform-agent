<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use SidewalkDevelopers\PlatformAgent\PlatformAgent;

function scheduledCommands(Schedule $schedule): array
{
    return array_map(fn ($event) => (string) $event->command, $schedule->events());
}

it('wires heartbeat, report, both split backups and both retention cleans in one call', function () {
    $schedule = app(Schedule::class);

    PlatformAgent::schedule($schedule);

    $commands = scheduledCommands($schedule);

    foreach ([
        'platform-agent:heartbeat',
        'platform-agent:report',
        'platform-agent:backup --kind=database',
        'platform-agent:backup --kind=files',
        'platform-agent:clean --kind=database',
        'platform-agent:clean --kind=files',
    ] as $needle) {
        expect(collect($commands)->contains(fn ($c) => str_contains($c, $needle)))
            ->toBeTrue("expected a scheduled command containing: {$needle}");
    }

    expect($schedule->events())->toHaveCount(6);
});

it('schedules the retention cleans daily at the configured time', function () {
    $schedule = app(Schedule::class);

    PlatformAgent::schedule($schedule);

    $cleans = collect($schedule->events())
        ->filter(fn ($event) => str_contains((string) $event->command, 'platform-agent:clean'));

    expect($cleans)->toHaveCount(2)
        ->and($cleans->pluck('expression')->unique()->all())->toBe(['0 3 * * *']); // default clean_at 03:00
});

it('honours a configured clean_at time', function () {
    config()->set('platform-agent.backup.clean_at', '04:30');

    $schedule = app(Schedule::class);
    PlatformAgent::schedule($schedule, (array) config('platform-agent'));

    $clean = collect($schedule->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'platform-agent:clean'));

    expect($clean->expression)->toBe('30 4 * * *');
});

it('omits the retention cleans when backup.clean_enabled is off', function () {
    config()->set('platform-agent.backup.clean_enabled', false);

    $schedule = app(Schedule::class);
    PlatformAgent::schedule($schedule, (array) config('platform-agent'));

    expect(collect(scheduledCommands($schedule))->contains(fn ($c) => str_contains($c, 'platform-agent:clean')))
        ->toBeFalse()
        ->and($schedule->events())->toHaveCount(4);
});

it('does not wire the restore poll when no deposit location is configured', function () {
    config()->set('platform-agent.restore.default_location', null);

    $schedule = app(Schedule::class);
    PlatformAgent::schedule($schedule, (array) config('platform-agent'));

    expect(collect(scheduledCommands($schedule))->contains(fn ($c) => str_contains($c, 'platform-agent:listen')))
        ->toBeFalse();
});

it('wires the Rule-6 restore poll fallback when a deposit location is set', function () {
    config()->set('platform-agent.restore.default_location', '/var/backups/restore');

    $schedule = app(Schedule::class);
    PlatformAgent::schedule($schedule, (array) config('platform-agent'));

    $poll = collect($schedule->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'platform-agent:listen --once'));

    expect($poll)->not->toBeNull()
        ->and($poll->expression)->toBe('*/5 * * * *');
});

it('beats the heartbeat every five minutes (Rule 2)', function () {
    $schedule = app(Schedule::class);

    PlatformAgent::schedule($schedule);

    $heartbeat = collect($schedule->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'platform-agent:heartbeat'));

    expect($heartbeat->expression)->toBe('*/5 * * * *');
});

it('honours configured per-kind backup cadences', function () {
    config()->set('platform-agent.backup.kinds.database.cadence', '0 */3 * * *');
    config()->set('platform-agent.backup.kinds.files.cadence', '30 1 * * *');

    $schedule = app(Schedule::class);
    PlatformAgent::schedule($schedule, (array) config('platform-agent'));

    $db = collect($schedule->events())
        ->first(fn ($event) => str_contains((string) $event->command, "--kind=database"));
    $files = collect($schedule->events())
        ->first(fn ($event) => str_contains((string) $event->command, "--kind=files"));

    expect($db->expression)->toBe('0 */3 * * *')
        ->and($files->expression)->toBe('30 1 * * *');
});
