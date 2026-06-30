<?php

declare(strict_types=1);

namespace SidewalkDevelopers\PlatformAgent;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Console\Kernel as Artisan;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use SidewalkDevelopers\PlatformAgent\Backup\ArchiveUploader;
use SidewalkDevelopers\PlatformAgent\Backup\BackupRunner;
use SidewalkDevelopers\PlatformAgent\Backup\SpatieBackupRunner;
use SidewalkDevelopers\PlatformAgent\Http\TusUploadClient;
use SidewalkDevelopers\PlatformAgent\Reporting\BackupRunReporter;
use SidewalkDevelopers\PlatformAgent\Reporting\EnvironmentReporter;
use SidewalkDevelopers\PlatformAgent\Console\BackupCommand;
use SidewalkDevelopers\PlatformAgent\Console\DiagnoseCommand;
use SidewalkDevelopers\PlatformAgent\Console\HeartbeatCommand;
use SidewalkDevelopers\PlatformAgent\Console\InstallCommand;
use SidewalkDevelopers\PlatformAgent\Console\RegisterCommand;
use SidewalkDevelopers\PlatformAgent\Console\ReportCommand;
use SidewalkDevelopers\PlatformAgent\Console\RestoreCommand;
use SidewalkDevelopers\PlatformAgent\Credentials\CredentialStore;
use SidewalkDevelopers\PlatformAgent\Credentials\DatabaseCredentialStore;
use SidewalkDevelopers\PlatformAgent\Http\PlatformClient;

/**
 * Auto-discovered package provider (extra.laravel.providers in composer.json).
 *
 * Binds the encrypted DB-backed CredentialStore (PA1), the PlatformClient
 * singleton, the command surface, and loads/publishes the package config +
 * credential migration. No backup/restore business logic yet (PA3/PA4).
 */
final class PlatformAgentServiceProvider extends ServiceProvider
{
    private const CONFIG_PATH = __DIR__.'/../config/platform-agent.php';

    private const MIGRATIONS_PATH = __DIR__.'/../database/migrations';

    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG_PATH, 'platform-agent');

        // Share one HTTP factory instance app-wide so PlatformClient and the
        // Http facade (incl. Http::fake() in tests) operate on the same object.
        $this->app->singleton(HttpFactory::class);

        // The credential seam — PA1 binds the encrypted DB-backed store over the
        // frozen interface. The runtime PAT is encrypted at rest; the enrollment
        // token comes from config. Never written back to `.env`.
        $this->app->singleton(CredentialStore::class, static function ($app): CredentialStore {
            return new DatabaseCredentialStore(
                config: $app['config'],
                db: $app->make(ConnectionResolverInterface::class),
                crypt: $app->make(Encrypter::class),
                logger: $app->bound(LoggerInterface::class) ? $app->make(LoggerInterface::class) : null,
            );
        });

        // Single source of the environment facts reported on register / heartbeat
        // / report (PA2). Shared so all surfaces report identical derived values.
        $this->app->singleton(EnvironmentReporter::class, static function ($app): EnvironmentReporter {
            return new EnvironmentReporter(
                app: $app->make(Application::class),
                config: (array) $app['config']->get('platform-agent', []),
            );
        });

        $this->app->singleton(PlatformClient::class, static function ($app): PlatformClient {
            return new PlatformClient(
                http: $app->make(HttpFactory::class),
                credentials: $app->make(CredentialStore::class),
                config: (array) $app['config']->get('platform-agent', []),
                logger: $app->bound(LoggerInterface::class) ? $app->make(LoggerInterface::class) : null,
            );
        });

        // --- Backup subsystem (PA3) ---------------------------------------

        // The tus resumable client for large/growing archives (>= threshold_bytes).
        $this->app->singleton(TusUploadClient::class, static function ($app): TusUploadClient {
            return new TusUploadClient(
                http: $app->make(HttpFactory::class),
                client: $app->make(PlatformClient::class),
                config: (array) $app['config']->get('platform-agent', []),
            );
        });

        // Transport selector: single-POST /agent/archives vs tus /agent/uploads.
        $this->app->singleton(ArchiveUploader::class, static function ($app): ArchiveUploader {
            return new ArchiveUploader(
                client: $app->make(PlatformClient::class),
                tus: $app->make(TusUploadClient::class),
                config: (array) $app['config']->get('platform-agent', []),
            );
        });

        // Run-log reporter for /agent/backup-runs (running START + terminal).
        $this->app->singleton(BackupRunReporter::class, static function ($app): BackupRunReporter {
            return new BackupRunReporter(
                client: $app->make(PlatformClient::class),
                env: $app->make(EnvironmentReporter::class),
            );
        });

        // Split spatie backup runner — seam (BackupRunner) so tests can fake it.
        $this->app->singleton(BackupRunner::class, static function ($app): BackupRunner {
            return new SpatieBackupRunner(
                artisan: $app->make(Artisan::class),
                events: $app->make(Dispatcher::class),
                config: $app->make(ConfigRepository::class),
            );
        });
    }

    public function boot(): void
    {
        // Loaded always so `php artisan migrate` discovers the credential table
        // in the customer app (package migration discipline — additive).
        $this->loadMigrationsFrom(self::MIGRATIONS_PATH);

        if ($this->app->runningInConsole()) {
            $this->publishes([
                self::CONFIG_PATH => $this->app->configPath('platform-agent.php'),
            ], 'platform-agent-config');

            $this->publishes([
                self::MIGRATIONS_PATH => $this->app->databasePath('migrations'),
            ], 'platform-agent-migrations');

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
            EnvironmentReporter::class,
            PlatformClient::class,
            TusUploadClient::class,
            ArchiveUploader::class,
            BackupRunReporter::class,
            BackupRunner::class,
        ];
    }
}
