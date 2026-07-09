<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel as Artisan;
use SidewalkDevelopers\PlatformAgent\Backup\SpatieBackupCleaner;

/**
 * Direct coverage for the concrete cleaner — the {@see \SidewalkDevelopers\PlatformAgent\Backup\BackupCleaner}
 * seam CleanCommandTest fakes. Proves the runtime spatie config scoping the
 * schedule relies on (per-kind name + temp disk + retention_days as keep-all).
 */
function cleaner(Artisan $artisan): SpatieBackupCleaner
{
    return new SpatieBackupCleaner($artisan, app('config'));
}

it('scopes spatie at run time: kind name, temp disk, retention as keep-all, graduated tiers zeroed', function () {
    // Simulate a customer's own cleanup strategy that must be overridden for
    // OUR scoped run (their disk cap stays untouched).
    config()->set('backup.cleanup.default_strategy.keep_all_backups_for_days', 7);
    config()->set('backup.cleanup.default_strategy.keep_daily_backups_for_days', 16);
    config()->set('backup.cleanup.default_strategy.delete_oldest_when_using_more_megabytes_than', 5000);

    $captured = [];

    $artisan = Mockery::mock(Artisan::class);
    $artisan->shouldReceive('call')
        ->once()
        ->withArgs(fn (string $command, array $params = []) => $command === 'backup:clean'
            && ($params['--disable-notifications'] ?? false) === true)
        ->andReturnUsing(function () use (&$captured): int {
            // Capture what spatie would READ at execution time.
            $captured = [
                'name' => config('backup.backup.name'),
                'disks' => config('backup.backup.destination.disks'),
                'keep_all' => config('backup.cleanup.default_strategy.keep_all_backups_for_days'),
                'keep_daily' => config('backup.cleanup.default_strategy.keep_daily_backups_for_days'),
                'keep_weekly' => config('backup.cleanup.default_strategy.keep_weekly_backups_for_weeks'),
                'keep_monthly' => config('backup.cleanup.default_strategy.keep_monthly_backups_for_months'),
                'keep_yearly' => config('backup.cleanup.default_strategy.keep_yearly_backups_for_years'),
                'mb_cap' => config('backup.cleanup.default_strategy.delete_oldest_when_using_more_megabytes_than'),
            ];

            return 0;
        });
    $artisan->shouldReceive('output')->andReturn('Cleanup completed!');

    $error = cleaner($artisan)->clean('database', 'platform-agent-db', 'local', 30);

    expect($error)->toBeNull()
        ->and($captured['name'])->toBe('platform-agent-db')
        ->and($captured['disks'])->toBe(['local'])
        ->and($captured['keep_all'])->toBe(30)
        ->and($captured['keep_daily'])->toBe(0)
        ->and($captured['keep_weekly'])->toBe(0)
        ->and($captured['keep_monthly'])->toBe(0)
        ->and($captured['keep_yearly'])->toBe(0)
        // The customer's global disk-safety cap is honored, not overridden.
        ->and($captured['mb_cap'])->toBe(5000);
});

it('surfaces a non-zero spatie exit as the error', function () {
    $artisan = Mockery::mock(Artisan::class);
    $artisan->shouldReceive('call')->once()->andReturn(1);
    $artisan->shouldReceive('output')->andReturn('backup:clean blew up');

    expect(cleaner($artisan)->clean('files', 'platform-agent-files', 'local', 14))
        ->toBe('backup:clean blew up');
});

it('surfaces a thrown spatie failure as the error', function () {
    $artisan = Mockery::mock(Artisan::class);
    $artisan->shouldReceive('call')->once()->andThrow(new RuntimeException('command not found'));

    expect(cleaner($artisan)->clean('files', 'platform-agent-files', 'local', 14))
        ->toBe('command not found');
});
