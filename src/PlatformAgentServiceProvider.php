<?php

declare(strict_types=1);

namespace SidewalkDevelopers\PlatformAgent;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use SidewalkDevelopers\PlatformAgent\Console\BackupCommand;
use SidewalkDevelopers\PlatformAgent\Console\DiagnoseCommand;
use SidewalkDevelopers\PlatformAgent\Console\HeartbeatCommand;
use SidewalkDevelopers\PlatformAgent\Console\InstallCommand;
use SidewalkDevelopers\PlatformAgent\Console\RegisterCommand;
use SidewalkDevelopers\PlatformAgent\Console\ReportCommand;
use SidewalkDevelopers\PlatformAgent\Console\RestoreCommand;
use SidewalkDevelopers\PlatformAgent\Credentials\ConfigCredentialStore;
use SidewalkDevelopers\PlatformAgent\Credentials\CredentialStore;
use SidewalkDevelopers\PlatformAgent\Http\PlatformClient;

/**
 * Auto-discovered package provider (extra.laravel.providers in composer.json).
 *
 * PA0 scope: merge + publish config, bind the CredentialStore seam and the
 * PlatformClient singleton, and register the command surface. No business logic.
 */
final class PlatformAgentServiceProvider extends ServiceProvider
{
    private const CONFIG_PATH = __DIR__.'/../config/platform-agent.php';

    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG_PATH, 'platform-agent');

        // Share one HTTP factory instance app-wide so PlatformClient and the
        // Http facade (incl. Http::fake() in tests) operate on the same object.
        $this->app->singleton(HttpFactory::class);

        // The credential seam. PA0 ships the config/array-backed stub; PA1
        // swaps in the encrypted-DB implementation + the enrollment exchange.
        $this->app->singleton(CredentialStore::class, static function ($app): CredentialStore {
            return new ConfigCredentialStore($app['config']);
        });

        $this->app->singleton(PlatformClient::class, static function ($app): PlatformClient {
            return new PlatformClient(
                http: $app->make(HttpFactory::class),
                credentials: $app->make(CredentialStore::class),
                config: (array) $app['config']->get('platform-agent', []),
                logger: $app->bound(LoggerInterface::class) ? $app->make(LoggerInterface::class) : null,
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                self::CONFIG_PATH => $this->app->configPath('platform-agent.php'),
            ], 'platform-agent-config');

            $this->commands([
                InstallCommand::class,
                DiagnoseCommand::class,
                RegisterCommand::class,
                HeartbeatCommand::class,
                ReportCommand::class,
                BackupCommand::class,
                RestoreCommand::class,
            ]);
        }
    }

    /**
     * @return array<int, string>
     */
    public function provides(): array
    {
        return [
            CredentialStore::class,
            PlatformClient::class,
        ];
    }
}
