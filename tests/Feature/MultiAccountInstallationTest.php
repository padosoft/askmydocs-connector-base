<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorBase\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorBase\Tests\TestCase;

/**
 * Multi-account support: a connector accepts MORE THAN ONE installation
 * per (tenant, connector_name), disambiguated by `label`, and each
 * installation can optionally bind to a `project_key`.
 *
 * R28-style: the composite unique is now (tenant_id, connector_name,
 * label) — per-tenant, label-disambiguated. R30/R31: the unique still
 * starts with tenant_id.
 */
final class MultiAccountInstallationTest extends TestCase
{
    use RefreshDatabase;

    public function test_label_defaults_to_default_when_omitted(): void
    {
        $installation = ConnectorInstallation::create([
            'tenant_id' => 'acme',
            'connector_name' => 'imap',
            'status' => ConnectorInstallation::STATUS_ACTIVE,
        ]);

        $this->assertSame('default', $installation->fresh()->label);
    }

    public function test_project_key_is_nullable_and_persists_when_set(): void
    {
        $bound = ConnectorInstallation::create([
            'tenant_id' => 'acme',
            'connector_name' => 'imap',
            'label' => 'support',
            'project_key' => 'acme-hr',
            'status' => ConnectorInstallation::STATUS_ACTIVE,
        ]);

        $unbound = ConnectorInstallation::create([
            'tenant_id' => 'acme',
            'connector_name' => 'imap',
            'label' => 'sales',
            'status' => ConnectorInstallation::STATUS_ACTIVE,
        ]);

        $this->assertSame('acme-hr', $bound->fresh()->project_key);
        $this->assertNull($unbound->fresh()->project_key);
    }

    public function test_same_connector_with_different_labels_is_allowed_in_one_tenant(): void
    {
        ConnectorInstallation::create([
            'tenant_id' => 'acme',
            'connector_name' => 'imap',
            'label' => 'support',
            'status' => ConnectorInstallation::STATUS_ACTIVE,
        ]);

        ConnectorInstallation::create([
            'tenant_id' => 'acme',
            'connector_name' => 'imap',
            'label' => 'sales',
            'status' => ConnectorInstallation::STATUS_ACTIVE,
        ]);

        $this->assertSame(
            2,
            ConnectorInstallation::query()
                ->forTenant('acme')
                ->where('connector_name', 'imap')
                ->count()
        );
    }

    public function test_same_connector_same_label_is_rejected_in_one_tenant(): void
    {
        ConnectorInstallation::create([
            'tenant_id' => 'acme',
            'connector_name' => 'imap',
            'label' => 'support',
            'status' => ConnectorInstallation::STATUS_ACTIVE,
        ]);

        $this->expectException(QueryException::class);

        ConnectorInstallation::create([
            'tenant_id' => 'acme',
            'connector_name' => 'imap',
            'label' => 'support',
            'status' => ConnectorInstallation::STATUS_ACTIVE,
        ]);
    }

    public function test_migration_is_rerunnable_after_rollback(): void
    {
        // Simulate a redeploy cycle: the migration already ran (test
        // bench setUp), now roll it back and re-run it. down() does NOT
        // restore the strict (tenant_id, connector_name) unique, so an
        // unguarded up() would try to drop a now-absent constraint and
        // throw. The guarded up() must converge cleanly.
        $migration = require __DIR__.'/../../database/migrations/2026_06_22_000001_add_label_and_project_key_to_connector_installations.php';

        $migration->down();
        $migration->up();

        $this->assertTrue(Schema::hasColumn('connector_installations', 'label'));
        $this->assertTrue(Schema::hasColumn('connector_installations', 'project_key'));
        $this->assertTrue(
            Schema::hasIndex('connector_installations', 'uq_connector_installations_tenant_name_label')
        );
    }

    public function test_same_label_allowed_across_two_tenants(): void
    {
        ConnectorInstallation::create([
            'tenant_id' => 'tenant-a',
            'connector_name' => 'imap',
            'label' => 'support',
            'status' => ConnectorInstallation::STATUS_ACTIVE,
        ]);

        ConnectorInstallation::create([
            'tenant_id' => 'tenant-b',
            'connector_name' => 'imap',
            'label' => 'support',
            'status' => ConnectorInstallation::STATUS_ACTIVE,
        ]);

        $this->assertSame(2, ConnectorInstallation::query()->where('connector_name', 'imap')->count());
    }
}
