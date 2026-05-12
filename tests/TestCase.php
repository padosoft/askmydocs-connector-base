<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorBase\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;
use Padosoft\AskMyDocsConnectorBase\ConnectorServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            ConnectorServiceProvider::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        // SQLite in-memory for fast, isolated tests. Mirrors AskMyDocs
        // host test bench so the same migrations run unchanged.
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            // SQLite ignores foreign-key cascades unless this flag is
            // ON. Production drivers (pgsql / mysql) enforce by
            // default — the migrations declare cascadeOnDelete and
            // tests must observe the same behaviour.
            'foreign_key_constraints' => true,
        ]);

        // Laravel Crypt needs an APP_KEY to encrypt/decrypt — Orchestra
        // doesn't auto-generate one for package tests.
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('app.cipher', 'AES-256-CBC');
    }
}
