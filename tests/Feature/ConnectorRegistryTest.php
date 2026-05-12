<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorBase\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Padosoft\AskMyDocsConnectorBase\ConnectorInterface;
use Padosoft\AskMyDocsConnectorBase\ConnectorRegistry;
use Padosoft\AskMyDocsConnectorBase\Exceptions\RegistryConfigurationException;
use Padosoft\AskMyDocsConnectorBase\HealthStatus;
use Padosoft\AskMyDocsConnectorBase\SyncResult;
use Padosoft\AskMyDocsConnectorBase\Tests\TestCase;

/**
 * ConnectorRegistry behaviour: built-in + composer auto-discovery,
 * R23 FQCN validation, duplicate-key detection.
 */
final class ConnectorRegistryTest extends TestCase
{
    public function test_built_in_connectors_load_from_config(): void
    {
        $registry = new ConnectorRegistry(
            $this->app,
            ['built_in' => [FakeConnectorForRegistryTest::class]],
            composerPackages: [],
        );

        $this->assertTrue($registry->has('fake-connector-for-test'));
        $this->assertInstanceOf(
            FakeConnectorForRegistryTest::class,
            $registry->get('fake-connector-for-test'),
        );
        $this->assertSame(['fake-connector-for-test'], $registry->keys());
    }

    public function test_composer_extra_connectors_load_when_present(): void
    {
        $registry = new ConnectorRegistry(
            $this->app,
            ['built_in' => []],
            composerPackages: [
                [
                    'name' => 'padosoft/askmydocs-connector-fake',
                    'extra' => [
                        'askmydocs' => [
                            'connectors' => [FakeConnectorForRegistryTest::class],
                        ],
                    ],
                ],
            ],
        );

        $this->assertTrue($registry->has('fake-connector-for-test'));
        $this->assertInstanceOf(
            FakeConnectorForRegistryTest::class,
            $registry->get('fake-connector-for-test'),
        );
    }

    public function test_invalid_fqcn_throws_at_boot(): void
    {
        $this->expectException(RegistryConfigurationException::class);
        $this->expectExceptionMessage('does not implement');

        new ConnectorRegistry(
            $this->app,
            ['built_in' => [NotAConnectorClass::class]],
            composerPackages: [],
        );
    }

    public function test_missing_class_throws_at_boot(): void
    {
        $this->expectException(RegistryConfigurationException::class);
        $this->expectExceptionMessage('does not exist');

        new ConnectorRegistry(
            $this->app,
            ['built_in' => ['Padosoft\\AskMyDocsConnectorBase\\GhostConnectorThatDoesNotExist']],
            composerPackages: [],
        );
    }

    public function test_duplicate_key_throws_at_boot(): void
    {
        $this->expectException(RegistryConfigurationException::class);
        $this->expectExceptionMessage('Duplicate connector key');

        new ConnectorRegistry(
            $this->app,
            ['built_in' => [
                FakeConnectorForRegistryTest::class,
                FakeConnectorForRegistryTestDuplicate::class,
            ]],
            composerPackages: [],
        );
    }

    public function test_get_returns_null_for_unknown_connector(): void
    {
        $registry = new ConnectorRegistry(
            $this->app,
            ['built_in' => []],
            composerPackages: [],
        );

        $this->assertNull($registry->get('does-not-exist'));
        $this->assertFalse($registry->has('does-not-exist'));
    }

    public function test_composer_extra_with_invalid_fqcn_string_throws(): void
    {
        $this->expectException(RegistryConfigurationException::class);

        new ConnectorRegistry(
            $this->app,
            ['built_in' => []],
            composerPackages: [
                [
                    'name' => 'padosoft/bad-pkg',
                    'extra' => [
                        'askmydocs' => [
                            'connectors' => [''],
                        ],
                    ],
                ],
            ],
        );
    }

    public function test_packages_without_extra_askmydocs_are_skipped_silently(): void
    {
        $registry = new ConnectorRegistry(
            $this->app,
            ['built_in' => []],
            composerPackages: [
                ['name' => 'symfony/yaml', 'extra' => ['branch-alias' => []]],
                ['name' => 'something/else'],
            ],
        );

        $this->assertSame([], $registry->keys());
    }
}

/**
 * Test double — a non-connector class to exercise R23 FQCN
 * validation.
 */
final class NotAConnectorClass
{
    public function key(): string
    {
        return 'not-real';
    }
}

/**
 * Test double — minimal connector implementation for built-in +
 * composer-extra discovery scenarios.
 */
final class FakeConnectorForRegistryTest implements ConnectorInterface
{
    public function key(): string
    {
        return 'fake-connector-for-test';
    }

    public function displayName(): string
    {
        return 'Fake';
    }

    public function iconUrl(): string
    {
        return '';
    }

    public function oauthScopes(): array
    {
        return [];
    }

    public function initiateOAuth(int $installationId): string
    {
        return '';
    }

    public function handleOAuthCallback(int $installationId, Request $request): void
    {
        //
    }

    public function syncFull(int $installationId): SyncResult
    {
        return SyncResult::empty();
    }

    public function syncIncremental(int $installationId, ?Carbon $since): SyncResult
    {
        return SyncResult::empty();
    }

    public function disconnect(int $installationId): void
    {
        //
    }

    public function health(int $installationId): HealthStatus
    {
        return HealthStatus::healthy();
    }
}

/**
 * Test double — collides with FakeConnectorForRegistryTest on `key()`
 * to exercise the duplicate-key boot guard.
 */
final class FakeConnectorForRegistryTestDuplicate implements ConnectorInterface
{
    public function key(): string
    {
        return 'fake-connector-for-test';
    }

    public function displayName(): string
    {
        return 'Fake Duplicate';
    }

    public function iconUrl(): string
    {
        return '';
    }

    public function oauthScopes(): array
    {
        return [];
    }

    public function initiateOAuth(int $installationId): string
    {
        return '';
    }

    public function handleOAuthCallback(int $installationId, Request $request): void
    {
        //
    }

    public function syncFull(int $installationId): SyncResult
    {
        return SyncResult::empty();
    }

    public function syncIncremental(int $installationId, ?Carbon $since): SyncResult
    {
        return SyncResult::empty();
    }

    public function disconnect(int $installationId): void
    {
        //
    }

    public function health(int $installationId): HealthStatus
    {
        return HealthStatus::healthy();
    }
}
