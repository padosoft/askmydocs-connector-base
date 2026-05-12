<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorBase\Tests\Unit;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Mockery;
use Padosoft\AskMyDocsConnectorBase\ConnectorInterface;
use Padosoft\AskMyDocsConnectorBase\ConnectorRegistry;
use Padosoft\AskMyDocsConnectorBase\ConnectorSyncJob;
use Padosoft\AskMyDocsConnectorBase\HealthStatus;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorBase\SyncResult;
use Padosoft\AskMyDocsConnectorBase\Tests\TestCase;

final class ConnectorSyncJobTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_missing_installation_short_circuits_silently(): void
    {
        $registry = Mockery::mock(ConnectorRegistry::class);
        $registry->shouldNotReceive('get');

        $job = new ConnectorSyncJob(999_999, 'default');
        $job->handle($registry, $this->app->make(TenantContext::class));

        $this->assertTrue(true); // reached end without throwing
    }

    public function test_non_active_status_is_skipped(): void
    {
        $installation = ConnectorInstallation::create([
            'tenant_id' => 'default',
            'connector_name' => 'fake',
            'status' => ConnectorInstallation::STATUS_PENDING,
        ]);

        $registry = Mockery::mock(ConnectorRegistry::class);
        $registry->shouldNotReceive('get');

        $job = new ConnectorSyncJob($installation->id, 'default');
        $job->handle($registry, $this->app->make(TenantContext::class));

        $this->assertSame(
            ConnectorInstallation::STATUS_PENDING,
            $installation->fresh()->status,
        );
    }

    public function test_unregistered_connector_marks_errored(): void
    {
        $installation = ConnectorInstallation::create([
            'tenant_id' => 'default',
            'connector_name' => 'ghost',
            'status' => ConnectorInstallation::STATUS_ACTIVE,
        ]);

        $registry = Mockery::mock(ConnectorRegistry::class);
        $registry->shouldReceive('get')->with('ghost')->andReturn(null);

        $job = new ConnectorSyncJob($installation->id, 'default');
        $job->handle($registry, $this->app->make(TenantContext::class));

        $fresh = $installation->fresh();
        $this->assertSame(ConnectorInstallation::STATUS_ERRORED, $fresh->status);
        $this->assertStringContainsString('no longer registered', $fresh->error_json['message']);
    }

    public function test_successful_sync_updates_last_sync_and_resets_errors(): void
    {
        $installation = ConnectorInstallation::create([
            'tenant_id' => 'default',
            'connector_name' => 'fake',
            'status' => ConnectorInstallation::STATUS_ACTIVE,
        ]);

        $connector = new SuccessFakeConnector;
        $registry = Mockery::mock(ConnectorRegistry::class);
        $registry->shouldReceive('get')->with('fake')->andReturn($connector);

        $job = new ConnectorSyncJob($installation->id, 'default');
        $job->handle($registry, $this->app->make(TenantContext::class));

        $fresh = $installation->fresh();
        $this->assertSame(ConnectorInstallation::STATUS_ACTIVE, $fresh->status);
        $this->assertNotNull($fresh->last_sync_at);
        $this->assertNull($fresh->error_json);
    }

    public function test_throwing_connector_marks_errored_and_rethrows(): void
    {
        $installation = ConnectorInstallation::create([
            'tenant_id' => 'default',
            'connector_name' => 'fake',
            'status' => ConnectorInstallation::STATUS_ACTIVE,
        ]);

        $connector = new ThrowingFakeConnector;
        $registry = Mockery::mock(ConnectorRegistry::class);
        $registry->shouldReceive('get')->with('fake')->andReturn($connector);

        $job = new ConnectorSyncJob($installation->id, 'default');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('upstream blew up');

        try {
            $job->handle($registry, $this->app->make(TenantContext::class));
        } finally {
            $fresh = $installation->fresh();
            $this->assertSame(ConnectorInstallation::STATUS_ERRORED, $fresh->status);
            $this->assertStringContainsString('upstream blew up', $fresh->error_json['message']);
        }
    }

    public function test_tenant_context_is_restored_after_job(): void
    {
        $installation = ConnectorInstallation::create([
            'tenant_id' => 'tenant-x',
            'connector_name' => 'fake',
            'status' => ConnectorInstallation::STATUS_ACTIVE,
        ]);

        $connector = new SuccessFakeConnector;
        $registry = Mockery::mock(ConnectorRegistry::class);
        $registry->shouldReceive('get')->with('fake')->andReturn($connector);

        $ctx = $this->app->make(TenantContext::class);
        $ctx->set('prior-tenant');

        $job = new ConnectorSyncJob($installation->id, 'tenant-x');
        $job->handle($registry, $ctx);

        // R30 — tenant context restored after the job, regardless of
        // whether the job itself ran under a different tenant.
        $this->assertSame('prior-tenant', $ctx->current());

        $ctx->reset();
    }
}

final class SuccessFakeConnector implements ConnectorInterface
{
    public function key(): string
    {
        return 'fake';
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

    public function handleOAuthCallback(int $installationId, Request $request): void {}

    public function syncFull(int $installationId): SyncResult
    {
        return SyncResult::empty();
    }

    public function syncIncremental(int $installationId, ?Carbon $since): SyncResult
    {
        return new SyncResult(1, 2, 0, [], Carbon::now());
    }

    public function disconnect(int $installationId): void {}

    public function health(int $installationId): HealthStatus
    {
        return HealthStatus::healthy();
    }
}

final class ThrowingFakeConnector implements ConnectorInterface
{
    public function key(): string
    {
        return 'fake';
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

    public function handleOAuthCallback(int $installationId, Request $request): void {}

    public function syncFull(int $installationId): SyncResult
    {
        return SyncResult::empty();
    }

    public function syncIncremental(int $installationId, ?Carbon $since): SyncResult
    {
        throw new \RuntimeException('upstream blew up');
    }

    public function disconnect(int $installationId): void {}

    public function health(int $installationId): HealthStatus
    {
        return HealthStatus::healthy();
    }
}
