<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorBase\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Padosoft\AskMyDocsConnectorBase\Tests\TestCase;

/**
 * The v1.3.1 backfill migration moves a legacy
 * `config_json['project_key']` onto the `project_key` column so the
 * column becomes the single source of truth (a legacy binding can then
 * be unbound by clearing the column).
 */
final class BackfillProjectKeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_moves_legacy_config_json_project_key_into_the_column(): void
    {
        $now = '2026-06-22 00:00:00';
        DB::table('connector_installations')->insert([
            'tenant_id' => 'acme',
            'connector_name' => 'imap',
            'label' => 'default',
            'project_key' => null,
            'config_json' => json_encode(['project_key' => 'legacy-proj', 'auth_mode' => 'basic']),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->runBackfill();

        $row = DB::table('connector_installations')->where('connector_name', 'imap')->first();
        $config = json_decode((string) $row->config_json, true);

        $this->assertSame('legacy-proj', $row->project_key);
        $this->assertArrayNotHasKey('project_key', $config);
        // Other config keys are preserved.
        $this->assertSame('basic', $config['auth_mode']);
    }

    public function test_keeps_the_column_but_strips_a_stale_legacy_key(): void
    {
        // Column already set AND a stale legacy key present: the column
        // must NOT be clobbered, but the legacy key MUST be removed so it
        // can never shadow the column (e.g. after the column is later
        // cleared to inherit the host default).
        $now = '2026-06-22 00:00:00';
        DB::table('connector_installations')->insert([
            'tenant_id' => 'acme',
            'connector_name' => 'notion',
            'label' => 'default',
            'project_key' => 'column-proj',
            'config_json' => json_encode(['project_key' => 'legacy-proj', 'workspace_id' => 'w1']),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->runBackfill();

        $row = DB::table('connector_installations')->where('connector_name', 'notion')->first();
        $config = json_decode((string) $row->config_json, true);

        $this->assertSame('column-proj', $row->project_key);
        $this->assertArrayNotHasKey('project_key', $config);
        $this->assertSame('w1', $config['workspace_id']);
    }

    public function test_is_a_noop_when_no_legacy_key_present(): void
    {
        $now = '2026-06-22 00:00:00';
        DB::table('connector_installations')->insert([
            'tenant_id' => 'acme',
            'connector_name' => 'jira',
            'label' => 'default',
            'project_key' => null,
            'config_json' => json_encode(['auth_mode' => 'basic']),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->runBackfill();

        $row = DB::table('connector_installations')->where('connector_name', 'jira')->first();
        $this->assertNull($row->project_key);
        $this->assertSame(['auth_mode' => 'basic'], json_decode((string) $row->config_json, true));
    }

    private function runBackfill(): void
    {
        $migration = require __DIR__.'/../../database/migrations/2026_06_22_000002_backfill_project_key_from_config_json.php';
        $migration->up();
    }
}
