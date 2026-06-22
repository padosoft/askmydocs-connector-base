<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-account + project-scoped connector installations.
 *
 * Before: exactly one installation per (tenant_id, connector_name) —
 * a tenant could connect a single IMAP mailbox / Drive account /
 * Notion workspace per connector.
 *
 * After: MORE THAN ONE installation per (tenant_id, connector_name),
 * disambiguated by a human-chosen `label` (e.g. "support", "sales").
 * The composite unique relaxes to (tenant_id, connector_name, label)
 * — still tenant-first (R30/R31), now label-disambiguated (R28-style).
 *
 * Each installation may optionally bind to a real `project_key`; an
 * empty binding falls back to the host's `kb.ingest.default_project`
 * (resolved once in `BaseConnector::resolveProjectKey()`), replacing
 * the old per-connector `connector-<key>` synthetic project.
 *
 * Backfill: `label` is added NOT NULL DEFAULT 'default', so every
 * pre-existing row becomes label='default' — preserving the previous
 * "one installation per connector" rows as the canonical default
 * account and keeping the new unique satisfied.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Idempotent / re-runnable: because down() intentionally does
        // NOT restore the original strict unique (see down()), a
        // migrate → rollback → migrate cycle (common on redeploys)
        // would otherwise hit an unconditional drop/add of a
        // constraint that no longer exists and abort. Every step is
        // guarded so up() converges to the same end-state from any
        // partial start. hasColumn/hasIndex are portable across the
        // SQLite test bench + Postgres + MySQL.
        Schema::table('connector_installations', function (Blueprint $table) {
            if (! Schema::hasColumn('connector_installations', 'label')) {
                $table->string('label', 64)->default('default');
            }
            if (! Schema::hasColumn('connector_installations', 'project_key')) {
                $table->string('project_key', 120)->nullable();
            }
        });

        // Drop the old (tenant_id, connector_name) unique in its own
        // statement — on SQLite this is a plain DROP INDEX, independent
        // of the column additions above. Only if it is still present.
        if (Schema::hasIndex('connector_installations', 'uq_connector_installations_tenant_name')) {
            Schema::table('connector_installations', function (Blueprint $table) {
                $table->dropUnique('uq_connector_installations_tenant_name');
            });
        }

        if (! Schema::hasIndex('connector_installations', 'uq_connector_installations_tenant_name_label')) {
            Schema::table('connector_installations', function (Blueprint $table) {
                $table->unique(
                    ['tenant_id', 'connector_name', 'label'],
                    'uq_connector_installations_tenant_name_label'
                );
            });
        }

        if (! Schema::hasIndex('connector_installations', 'idx_connector_installations_tenant_project')) {
            Schema::table('connector_installations', function (Blueprint $table) {
                // Tenant-scoped project filtering ("which accounts feed
                // project X for this tenant") — composite, tenant-first.
                $table->index(
                    ['tenant_id', 'project_key'],
                    'idx_connector_installations_tenant_project'
                );
            });
        }
    }

    /**
     * Reverse the additive columns + relaxed unique.
     *
     * NOTE: this deliberately does NOT restore the original strict
     * `(tenant_id, connector_name)` unique. Once multi-account rows
     * exist, dropping `label` collapses N accounts into N identical
     * `(tenant_id, connector_name)` rows — re-adding the strict unique
     * would fail (or silently demand data loss). A clean, non-
     * destructive rollback therefore drops only what `up()` added and
     * leaves the table without a `(tenant_id, connector_name)` unique.
     * An operator who genuinely needs the old strict unique back must
     * first deduplicate installations down to one per connector and add
     * the constraint by hand.
     */
    public function down(): void
    {
        Schema::table('connector_installations', function (Blueprint $table) {
            $table->dropUnique('uq_connector_installations_tenant_name_label');
            $table->dropIndex('idx_connector_installations_tenant_project');
        });

        Schema::table('connector_installations', function (Blueprint $table) {
            $table->dropColumn(['label', 'project_key']);
        });
    }
};
