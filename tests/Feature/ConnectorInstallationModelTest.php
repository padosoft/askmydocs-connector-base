<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorBase\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorCredential;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorBase\Tests\TestCase;

/**
 * R30 + R31 — tenant_id auto-fills from TenantContext, forTenant
 * scope filters cross-tenant rows out of every query.
 */
final class ConnectorInstallationModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_id_auto_fills_from_tenant_context_on_create(): void
    {
        $this->app->make(TenantContext::class)->set('acme');

        $installation = ConnectorInstallation::create([
            'connector_name' => 'google-drive',
            'status' => ConnectorInstallation::STATUS_PENDING,
        ]);

        $this->assertSame('acme', $installation->tenant_id);

        $this->app->make(TenantContext::class)->reset();
    }

    public function test_explicit_tenant_id_is_preserved_on_create(): void
    {
        $this->app->make(TenantContext::class)->set('default');

        $installation = ConnectorInstallation::create([
            'tenant_id' => 'override-tenant',
            'connector_name' => 'google-drive',
            'status' => ConnectorInstallation::STATUS_PENDING,
        ]);

        $this->assertSame('override-tenant', $installation->tenant_id);
    }

    public function test_for_tenant_scope_isolates_rows_per_tenant(): void
    {
        ConnectorInstallation::create([
            'tenant_id' => 'tenant-a',
            'connector_name' => 'google-drive',
            'status' => ConnectorInstallation::STATUS_ACTIVE,
        ]);
        ConnectorInstallation::create([
            'tenant_id' => 'tenant-b',
            'connector_name' => 'google-drive',
            'status' => ConnectorInstallation::STATUS_ACTIVE,
        ]);

        $this->assertSame(2, ConnectorInstallation::query()->count());
        $this->assertSame(1, ConnectorInstallation::query()->forTenant('tenant-a')->count());
        $this->assertSame(1, ConnectorInstallation::query()->forTenant('tenant-b')->count());
        $this->assertSame(0, ConnectorInstallation::query()->forTenant('tenant-c')->count());
    }

    public function test_composite_unique_tenant_name_allows_same_connector_in_two_tenants(): void
    {
        // Same connector_name + DIFFERENT tenant_id MUST be allowed
        // — two tenants can legitimately install the same connector.
        ConnectorInstallation::create([
            'tenant_id' => 'tenant-a',
            'connector_name' => 'google-drive',
            'status' => ConnectorInstallation::STATUS_ACTIVE,
        ]);

        ConnectorInstallation::create([
            'tenant_id' => 'tenant-b',
            'connector_name' => 'google-drive',
            'status' => ConnectorInstallation::STATUS_ACTIVE,
        ]);

        $this->assertSame(2, ConnectorInstallation::query()->where('connector_name', 'google-drive')->count());
    }

    public function test_credential_cascade_on_installation_delete(): void
    {
        $installation = ConnectorInstallation::create([
            'tenant_id' => 'default',
            'connector_name' => 'google-drive',
            'status' => ConnectorInstallation::STATUS_ACTIVE,
        ]);

        ConnectorCredential::create([
            'tenant_id' => 'default',
            'connector_installation_id' => $installation->id,
            'encrypted_access_token' => 'opaque-bytes',
        ]);

        $this->assertDatabaseHas('connector_credentials', [
            'connector_installation_id' => $installation->id,
        ]);

        $installation->delete();

        $this->assertDatabaseMissing('connector_credentials', [
            'connector_installation_id' => $installation->id,
        ]);
    }
}
