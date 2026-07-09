<?php

declare(strict_types=1);

namespace SidewalkDevelopers\PlatformAgent\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use SidewalkDevelopers\PlatformAgent\PlatformAgentServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            PlatformAgentServiceProvider::class,
        ];
    }

    /**
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:hpmtnoLBAzMYbqFXRvyrrNB+pFA0W2M3JBr81qO6RXc=');

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Per-test isolated cache (telemetry storage-usage memoization).
        $app['config']->set('cache.default', 'array');

        $app['config']->set('platform-agent.url', 'https://hub.platform.test');
        $app['config']->set('platform-agent.api_prefix', 'api/v1');
        $app['config']->set('platform-agent.token', 'enrollment-token-fixture');
        $app['config']->set('platform-agent.application_uuid', '9d1f2c34-5b6a-4c7d-8e9f-0a1b2c3d4e5f');
        $app['config']->set('platform-agent.agent_version', '1.4.0');
        $app['config']->set('platform-agent.http.retries', 1);
        $app['config']->set('platform-agent.http.retry_delay_ms', 0);
    }

    /**
     * Load the `body` of a pinned Hub-contract fixture.
     *
     * @return array<string, mixed>
     */
    protected function fixtureBody(string $name): array
    {
        $path = __DIR__.'/Fixtures/hub-contract/'.$name;

        if (! is_file($path)) {
            throw new \RuntimeException("Missing contract fixture: {$name}");
        }

        /** @var array{body?: array<string, mixed>} $decoded */
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return $decoded['body'] ?? [];
    }
}
