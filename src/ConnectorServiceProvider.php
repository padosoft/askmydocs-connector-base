<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorBase;

use Illuminate\Support\ServiceProvider;
use Padosoft\AskMyDocsConnectorBase\Auth\OAuthCredentialVault;
use Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract;
use Padosoft\AskMyDocsConnectorBase\Contracts\NullConnectorIngestionContract;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;

/**
 * Service provider for the connector framework.
 *
 * Registers:
 *   - {@see TenantContext} as a singleton (only if the host app has
 *     not already bound its own — host apps with a richer
 *     tenant-resolution layer rebind the abstract via an alias).
 *   - {@see OAuthCredentialVault} as a singleton so the request-
 *     scoped tenant context flows through every credential read.
 *   - {@see ConnectorRegistry} as a singleton with composer-lock
 *     auto-discovery. R23 — every registered FQCN is validated at
 *     boot.
 *   - The two connector migrations (auto-loaded; publishable via the
 *     `connector-migrations` tag for host apps that prefer copies
 *     under their own `database/migrations/`).
 *   - The default `config/connectors.php` (publishable for hosts
 *     that need to customise providers / cadence).
 */
class ConnectorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/connectors.php',
            'connectors',
        );

        // Bind TenantContext as a singleton only if the host has not
        // already provided one. This lets AskMyDocs keep its own
        // `App\Support\TenantContext` and alias it via the container
        // binding so both surfaces observe the same active tenant.
        if (! $this->app->bound(TenantContext::class)) {
            $this->app->singleton(TenantContext::class);
        }

        $this->app->singleton(OAuthCredentialVault::class);

        // Fail-loud default for the ingestion bridge — host
        // applications MUST rebind this to a real implementation that
        // talks to their ingest pipeline. See
        // {@see ConnectorIngestionContract} for the contract.
        if (! $this->app->bound(ConnectorIngestionContract::class)) {
            $this->app->singleton(
                ConnectorIngestionContract::class,
                NullConnectorIngestionContract::class,
            );
        }

        $this->app->singleton(ConnectorRegistry::class, function ($app) {
            return new ConnectorRegistry($app, (array) config('connectors', []));
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'connector-migrations');

            $this->publishes([
                __DIR__.'/../config/connectors.php' => config_path('connectors.php'),
            ], 'connector-config');
        }
    }
}
