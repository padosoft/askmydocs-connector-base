<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Normalise the project binding onto the `project_key` column.
 *
 * v1.3.0 added the `project_key` column but installs created before it
 * existed kept the binding in `config_json['project_key']`. Resolving
 * from the column alone silently re-scoped them; `resolveProjectKey()`
 * gained a config_json fallback (v1.3.1) so they keep working, but that
 * fallback alone left the column unable to override the legacy value —
 * an operator could not unbind a legacy install to inherit the host
 * default by clearing the column, because the hidden config key kept
 * winning.
 *
 * This migration MOVES the legacy value: it copies a non-empty
 * `config_json['project_key']` into the `project_key` column (only when
 * the column is still empty) and removes the key from `config_json`.
 * After it runs the column is the single source of truth — clearing it
 * inherits the host default, exactly as the v1.3 contract intends. The
 * `resolveProjectKey()` config_json fallback remains a harmless safety
 * net for any row this migration did not reach (e.g. created in the
 * window between deploying v1.3.0 and running this migration).
 *
 * Idempotent: re-running is a no-op once the keys have moved. JSON is
 * decoded/encoded in PHP (not via SQL JSON functions) so it is portable
 * across the SQLite test bench + Postgres + MySQL. R3 memory-safe via
 * chunkById.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('connector_installations')
            ->orderBy('id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    $config = json_decode((string) ($row->config_json ?? ''), true);
                    if (! is_array($config) || ! array_key_exists('project_key', $config)) {
                        // Nothing legacy to migrate or clean up.
                        continue;
                    }

                    $legacy = $config['project_key'];

                    // ALWAYS strip the legacy key so config_json can never
                    // shadow the column after this migration — even when the
                    // column is already set (e.g. an operator rebound it
                    // between the v1.3 migration and this one). Leaving it
                    // would block unbinding to the host default later.
                    unset($config['project_key']);

                    $update = ['config_json' => json_encode($config)];

                    // Adopt the legacy value into the column ONLY when the
                    // column is still empty — never clobber an explicit binding.
                    $column = $row->project_key ?? null;
                    if ((! is_string($column) || $column === '') && is_string($legacy) && $legacy !== '') {
                        $update['project_key'] = $legacy;
                    }

                    DB::table('connector_installations')
                        ->where('id', $row->id)
                        ->update($update);
                }
            });
    }

    public function down(): void
    {
        // No-op: a move is not reliably reversible (the original
        // config_json shape is not retained), and the column remains a
        // valid, readable source either way.
    }
};
