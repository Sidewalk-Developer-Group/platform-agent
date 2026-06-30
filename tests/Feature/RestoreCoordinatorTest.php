<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use SidewalkDevelopers\PlatformAgent\Credentials\CredentialStore;
use SidewalkDevelopers\PlatformAgent\Restore\RestoreCoordinator;

/*
|--------------------------------------------------------------------------
| RestoreCoordinator — drain every downloadable job (PA5)
|--------------------------------------------------------------------------
*/

function enrolForSweep(): void
{
    app(CredentialStore::class)->putRuntimeToken('runtime-pat-fixture', [
        'abilities' => ['app:restore'],
    ]);
}

function sweepDir(): string
{
    $dir = sys_get_temp_dir().'/pa-sweep-'.bin2hex(random_bytes(6));
    mkdir($dir, 0o755, true);

    return $dir;
}

/** Fake the full restore contract so the one indexed job verifies + deposits. */
function fakeSweepContract(string $bytes, bool $tamper = false): void
{
    $manifest = test()->fixtureBody('restore-jobs.download.json');
    $manifest['data']['archive']['sha256'] = $tamper ? str_repeat('0', 64) : hash('sha256', $bytes);
    $manifest['data']['archive']['size_bytes'] = strlen($bytes);
    $manifest['data']['download_url'] = 'https://hub.platform.test/origin-egress/archive.zip?signature=abc';

    Http::fake([
        'https://hub.platform.test/api/v1/agent/restore-jobs' => Http::response(test()->fixtureBody('restore-jobs.index.json'), 200),
        '*/agent/restore-jobs/*/download' => Http::response($manifest, 200),
        '*/origin-egress/archive.zip*' => Http::response($bytes, 200),
        '*/agent/restore-jobs/*/report' => Http::response(test()->fixtureBody('restore-jobs.report.success.json'), 200),
    ]);
}

it('drains a downloadable job: pulls, verifies, deposits and reports', function () {
    enrolForSweep();
    $bytes = str_repeat('SWEEP', 512);
    fakeSweepContract($bytes);
    $dir = sweepDir();

    $sweep = app(RestoreCoordinator::class)->drain($dir);

    expect($sweep->considered)->toBe(1)
        ->and($sweep->deposited)->toBe(1)
        ->and($sweep->failed)->toBe(0);

    Http::assertSent(fn ($r) => str_contains($r->url(), '/report')
        && $r->method() === 'POST'
        && $r['success'] === true);
});

it('counts a Rule-4 checksum mismatch as a reported failure, not a deposit', function () {
    enrolForSweep();
    fakeSweepContract(str_repeat('SWEEP', 512), tamper: true);
    $dir = sweepDir();

    $sweep = app(RestoreCoordinator::class)->drain($dir);

    expect($sweep->considered)->toBe(1)
        ->and($sweep->deposited)->toBe(0)
        ->and($sweep->failed)->toBe(1);

    // The failure is reported (no silent failure).
    Http::assertSent(fn ($r) => str_contains($r->url(), '/report') && $r['success'] === false);
});

it('reports a discovery failure when the job list cannot be retrieved', function () {
    enrolForSweep();
    Http::fake([
        '*/agent/restore-jobs' => Http::response([
            'success' => false,
            'message' => 'Server error',
            'data' => null,
        ], 500),
    ]);

    $sweep = app(RestoreCoordinator::class)->drain(sweepDir());

    expect($sweep->discoveryFailed)->toBeTrue()
        ->and($sweep->reason)->toContain('Could not list restore jobs');
});

it('ignores non-downloadable jobs', function () {
    enrolForSweep();
    $index = test()->fixtureBody('restore-jobs.index.json');
    $index['data']['restore_jobs'][0]['is_downloadable'] = false;

    Http::fake([
        '*/agent/restore-jobs' => Http::response($index, 200),
    ]);

    $sweep = app(RestoreCoordinator::class)->drain(sweepDir());

    expect($sweep->considered)->toBe(0)
        ->and($sweep->deposited)->toBe(0);
});
