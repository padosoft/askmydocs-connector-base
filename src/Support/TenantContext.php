<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorBase\Support;

/**
 * TenantContext — request-scoped singleton holding the active tenant id.
 *
 * Set by the host application's tenant-resolution middleware (HTTP) or
 * by a `--tenant=X` option on artisan commands that touch tenant-aware
 * data. Defaults to `'default'` so single-tenant deployments keep
 * working without any wiring.
 *
 * R31: every tenant-aware query in this package funnels through this
 * context. Host applications that already ship their own
 * `TenantContext` (e.g. AskMyDocs `App\Support\TenantContext`) should
 * alias their own class to this one via a container binding so both
 * sides observe the same active tenant.
 */
final class TenantContext
{
    private const DEFAULT_TENANT = 'default';

    private string $tenantId = self::DEFAULT_TENANT;

    public function set(string $tenantId): void
    {
        $tenantId = trim($tenantId);
        if ($tenantId === '') {
            $tenantId = self::DEFAULT_TENANT;
        }

        // Validate format: lowercase alphanumeric + dash + underscore, max 50 chars.
        // Mirrors the column constraint and what host middleware accepts as input.
        if (! preg_match('/^[a-z0-9_-]{1,50}$/', $tenantId)) {
            throw new \InvalidArgumentException(
                "Invalid tenant_id format: must match /^[a-z0-9_-]{1,50}$/, got '{$tenantId}'"
            );
        }

        $this->tenantId = $tenantId;
    }

    public function current(): string
    {
        return $this->tenantId;
    }

    public function isDefault(): bool
    {
        return $this->tenantId === self::DEFAULT_TENANT;
    }

    public function reset(): void
    {
        $this->tenantId = self::DEFAULT_TENANT;
    }
}
