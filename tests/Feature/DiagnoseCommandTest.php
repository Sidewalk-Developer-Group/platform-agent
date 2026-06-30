<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use SidewalkDevelopers\PlatformAgent\Credentials\CredentialStore;

it('reports an absent runtime token and redacts the enrollment token', function () {
    Http::fake(['*' => Http::response('', 200)]);

    Artisan::call('platform-agent:diagnose');
    $output = Artisan::output();

    expect($output)->toContain('absent')
        ->and($output)->toContain('platform-agent:install')
        // The enrollment token secret is never printed in full.
        ->and($output)->not->toContain('enrollment-token-fixture');
});

it('reports a present runtime token without ever printing the secret', function () {
    app(CredentialStore::class)->putRuntimeToken('2|SUPER-SECRET-RUNTIME', [
        'token_id' => '4d5e6f7a-8b9c-4d0e-1f2a-3b4c5d6e7f8a',
        'abilities' => ['app:backup', 'app:heartbeat', 'app:restore'],
        'expires_at' => null,
    ]);

    Http::fake(['*' => Http::response('', 200)]);

    Artisan::call('platform-agent:diagnose');
    $output = Artisan::output();

    expect($output)->toContain('present, encrypted at rest')
        ->and($output)->toContain('app:backup')
        ->and($output)->not->toContain('SUPER-SECRET-RUNTIME');
});

it('reports connectivity to the Cloud Hub', function () {
    Http::fake(['*' => Http::response('', 200)]);

    Artisan::call('platform-agent:diagnose');

    expect(Artisan::output())->toContain('reachable');
});
