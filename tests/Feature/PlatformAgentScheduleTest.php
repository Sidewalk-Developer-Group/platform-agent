<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use SidewalkDevelopers\PlatformAgent\PlatformAgent;

function scheduledCommands(Schedule $schedule): array
{
    return array_map(fn ($event) => (string) $event->command, $schedule->events());
}

it('wires heartbeat, report and both split backups in one call', function () {
    $schedule = app(Schedule::class);

    PlatformAgent::schedule($schedule);

    $commands = scheduledCommands($schedule);

    foreach ([
        'platform-agent:heartbeat',
        'platform-agent:report',
        '--kind=database',
        '--kind=files',
    ] as $needle) {
        expect(collect($commands)->contains(fn ($c) => str_contains($c, $needle)))
            ->toBeTrue("expected a scheduled command containing: {$needle}");
    }

    expect($schedule->events())->toHaveCount(4);
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
