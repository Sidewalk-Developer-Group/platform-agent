<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel as Artisan;
use Illuminate\Support\Facades\Storage;
use SidewalkDevelopers\PlatformAgent\Backup\SpatieBackupRunner;

/**
 * Direct coverage for the concrete runner — the seam BackupCommandTest fakes.
 *
 * Regression for the live bug: the runner passed `--disable-notifications`, which
 * (in real spatie) suppresses BackupWasSuccessful / BackupZipWasCreated — both
 * fire only via sendNotification() — so the event-based path capture NEVER ran
 * and every successful backup was reported as failed. The runner now locates the
 * archive by diffing the destination disk, independent of those events.
 */
function runner(Artisan $artisan): SpatieBackupRunner
{
    return new SpatieBackupRunner($artisan, app('config'));
}

it('locates the archive spatie writes to the destination disk (no events needed)', function () {
    Storage::fake('local');

    $artisan = Mockery::mock(Artisan::class);
    // Mimic real spatie: --disable-notifications, no events, just a zip on disk.
    $artisan->shouldReceive('call')->once()->andReturnUsing(function (): int {
        Storage::disk('local')->put('platform-agent-files/2026-07-02.zip', str_repeat('x', 2048));

        return 0;
    });
    $artisan->shouldReceive('output')->andReturn('Backup completed!');

    $result = runner($artisan)->run('files', 'platform-agent-files', 'local');

    expect($result->ok)->toBeTrue()
        ->and($result->archivePath)->toBe(Storage::disk('local')->path('platform-agent-files/2026-07-02.zip'))
        ->and($result->sizeBytes)->toBe(2048)
        ->and($result->error)->toBeNull();
});

it('ignores pre-existing archives and picks only the one this run produced', function () {
    Storage::fake('local');
    // An older archive from a previous run must not be mistaken for this one.
    Storage::disk('local')->put('platform-agent-files/old.zip', 'old');
    touch(Storage::disk('local')->path('platform-agent-files/old.zip'), time() - 3600);

    $artisan = Mockery::mock(Artisan::class);
    $artisan->shouldReceive('call')->once()->andReturnUsing(function (): int {
        Storage::disk('local')->put('platform-agent-files/new.zip', str_repeat('y', 4096));

        return 0;
    });
    $artisan->shouldReceive('output')->andReturn('ok');

    $result = runner($artisan)->run('files', 'platform-agent-files', 'local');

    expect($result->ok)->toBeTrue()
        ->and($result->archivePath)->toBe(Storage::disk('local')->path('platform-agent-files/new.zip'))
        ->and($result->sizeBytes)->toBe(4096);
});

it('fails when spatie exits 0 but produces no archive', function () {
    Storage::fake('local');

    $artisan = Mockery::mock(Artisan::class);
    $artisan->shouldReceive('call')->once()->andReturn(0);
    $artisan->shouldReceive('output')->andReturn('Nothing produced.');

    $result = runner($artisan)->run('files', 'platform-agent-files', 'local');

    expect($result->ok)->toBeFalse()
        ->and($result->archivePath)->toBeNull()
        ->and($result->error)->toBe('Nothing produced.');
});

it('fails when spatie returns a non-zero exit code', function () {
    Storage::fake('local');

    $artisan = Mockery::mock(Artisan::class);
    $artisan->shouldReceive('call')->once()->andReturn(1);
    $artisan->shouldReceive('output')->andReturn('backup:run blew up');

    $result = runner($artisan)->run('database', 'platform-agent-db', 'local');

    expect($result->ok)->toBeFalse()
        ->and($result->error)->toBe('backup:run blew up');
});
