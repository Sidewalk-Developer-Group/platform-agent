<?php

declare(strict_types=1);

use SidewalkDevelopers\PlatformAgent\Backup\BackupCleaner;

/**
 * Bind a fake BackupCleaner recording the exact scoping it was asked for.
 *
 * @return object{calls: array<int, array<string, mixed>>}
 */
function fakeCleaner(?string $error = null): object
{
    $fake = new class($error) implements BackupCleaner
    {
        /** @var array<int, array<string, mixed>> */
        public array $calls = [];

        public function __construct(private readonly ?string $error)
        {
        }

        public function clean(string $kind, string $spatieName, string $tempDisk, int $retentionDays): ?string
        {
            $this->calls[] = compact('kind', 'spatieName', 'tempDisk', 'retentionDays');

            return $this->error;
        }
    };

    app()->instance(BackupCleaner::class, $fake);

    return $fake;
}

it('cleans a kind with its configured retention and derived spatie name', function () {
    $fake = fakeCleaner();

    $this->artisan('platform-agent:clean --kind=database')
        ->expectsOutputToContain('keep 30 days')
        ->assertExitCode(0);

    expect($fake->calls)->toBe([[
        'kind' => 'database',
        'spatieName' => 'platform-agent-db',
        'tempDisk' => 'local',
        'retentionDays' => 30,
    ]]);
});

it('honours a per-kind spatie_name and retention override', function () {
    config()->set('platform-agent.backup.kinds.files.spatie_name', 'acme-files');
    config()->set('platform-agent.backup.kinds.files.retention_days', 7);

    $fake = fakeCleaner();

    $this->artisan('platform-agent:clean --kind=files')->assertExitCode(0);

    expect($fake->calls[0]['spatieName'])->toBe('acme-files')
        ->and($fake->calls[0]['retentionDays'])->toBe(7);
});

it('rejects an invalid --kind', function () {
    $fake = fakeCleaner();

    $this->artisan('platform-agent:clean --kind=everything')
        ->expectsOutputToContain('Invalid --kind')
        ->assertExitCode(1);

    expect($fake->calls)->toBe([]);
});

it('skips (successfully) when retention is disabled for the kind', function () {
    config()->set('platform-agent.backup.kinds.database.retention_days', 0);

    $fake = fakeCleaner();

    $this->artisan('platform-agent:clean --kind=database')
        ->expectsOutputToContain('disabled')
        ->assertExitCode(0);

    expect($fake->calls)->toBe([]);
});

it('fails loudly when the clean errors', function () {
    fakeCleaner(error: 'disk on fire');

    $this->artisan('platform-agent:clean --kind=database')
        ->expectsOutputToContain('disk on fire')
        ->assertExitCode(1);
});
