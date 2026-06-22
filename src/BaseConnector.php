<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorBase;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Padosoft\AskMyDocsConnectorBase\Auth\OAuthCredentialVault;
use Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;

/**
 * Default implementations shared by every concrete connector.
 *
 * Subclasses MUST still implement {@see ConnectorInterface}'s
 * abstract surface (`key()`, `displayName()`, `iconUrl()`,
 * `oauthScopes()`, `initiateOAuth()`, `handleOAuthCallback()`,
 * `syncFull()`, `syncIncremental()`, `disconnect()`, `health()`).
 * This base class provides reusable helpers:
 *
 *   - {@see issueOAuthState()} / {@see consumeOAuthState()} —
 *     CSRF-safe state-token round-trip via the application cache. TTL
 *     controlled by `connectors.oauth_state_ttl_seconds` (default
 *     600).
 *   - {@see refreshTokenIfExpired()} — placeholder helper that
 *     concrete connectors override per-provider. The default
 *     implementation is a no-op (returns the current access token if
 *     present, null otherwise) — useful for providers without
 *     refresh tokens (e.g. some PATs).
 *   - {@see loadInstallation()} — defence-in-depth tenant + connector
 *     scoped installation lookup that throws on mismatch.
 *
 * Host applications (AskMyDocs core, downstream consumers) typically
 * extend this class once more to add host-specific helpers — e.g.
 * audit-trail emission, PII redaction at the ingest boundary,
 * KB-disk path resolution — that depend on host services not present
 * in this standalone base package.
 */
abstract class BaseConnector implements ConnectorInterface
{
    public function __construct(
        protected readonly OAuthCredentialVault $vault,
        protected readonly TenantContext $tenantContext,
        protected readonly ConnectorIngestionContract $ingestion,
    ) {}

    /**
     * @return list<string>
     */
    public function oauthScopes(): array
    {
        return [];
    }

    public function iconUrl(): string
    {
        return asset("connectors/{$this->key()}.svg");
    }

    /**
     * Default no-op refresh. Override per-provider — e.g. Google's
     * `https://oauth2.googleapis.com/token` POST with
     * `grant_type=refresh_token`.
     */
    public function refreshTokenIfExpired(int $installationId): ?string
    {
        return $this->vault->getAccessToken($installationId);
    }

    /**
     * Proxy to the host's ingest pipeline. Connectors call this for
     * every document body they fetch — see
     * {@see ConnectorIngestionContract::dispatchIngestion()}.
     *
     * @param  array<string,mixed>  $metadata
     */
    protected function dispatchIngestion(
        string $projectKey,
        string $relativePath,
        string $disk,
        string $title,
        array $metadata,
        string $mimeType,
        string $tenantId,
    ): void {
        $this->ingestion->dispatchIngestion(
            $projectKey,
            $relativePath,
            $disk,
            $title,
            $metadata,
            $mimeType,
            $tenantId,
        );
    }

    /**
     * Single source of truth for the project a connector ingests into.
     *
     * Returns the installation's explicit `project_key` when set,
     * otherwise the host's `kb.ingest.default_project` config (itself
     * defaulting to the literal `default`). This replaces the old
     * `connector-<key>` synthetic-project fallback that was duplicated
     * across all eight concrete connectors — they now call
     * `$this->resolveProjectKey($installation)` instead.
     */
    protected function resolveProjectKey(ConnectorInstallation $installation): string
    {
        $projectKey = $installation->project_key;

        if (is_string($projectKey) && $projectKey !== '') {
            return $projectKey;
        }

        // The host MAY define kb.ingest.default_project; treat an
        // absent / null / empty value as the literal 'default' so the
        // connector never ingests into an empty project key.
        $hostDefault = config('kb.ingest.default_project');

        return is_string($hostDefault) && $hostDefault !== '' ? $hostDefault : 'default';
    }

    /**
     * Resolve a relative KB path to disk + absolute form.
     *
     * @return array{relative: string, absolute: string, disk: string}
     */
    protected function resolveKbSourcePath(string $relativePath): array
    {
        return $this->ingestion->resolveKbSourcePath($relativePath);
    }

    /**
     * R26 — PII redaction at the ingest boundary. Connectors call this
     * on every document body BEFORE handing it to the host pipeline.
     */
    protected function maybeRedactContent(string $content): string
    {
        return $this->ingestion->redactContent($content);
    }

    /**
     * Emit an immutable audit row for the admin log viewer. The
     * connector key is filled in from {@see ConnectorInterface::key()}.
     *
     * @param  array<string,mixed>|null  $metadata
     */
    protected function emitAudit(
        string $eventType,
        ?int $installationId = null,
        ?array $metadata = null,
    ): void {
        $this->ingestion->emitAudit($this->key(), $eventType, $installationId, $metadata);
    }

    /**
     * Route a provider-side deletion event to the host's deletion
     * service. The connector stashes the remote-id under
     * `knowledge_documents.metadata` at ingest time; on deletion the
     * host looks it up tenant-scoped and soft-deletes the matching
     * documents. Returns true when at least one row was acted upon.
     */
    protected function softDeleteByMetadataKey(
        ConnectorInstallation $installation,
        string $metadataKey,
        string $remoteId,
    ): bool {
        return $this->ingestion->softDeleteByRemoteId($installation, $metadataKey, $remoteId);
    }

    /**
     * Generate a CSRF state token + store it in the cache, keyed to
     * the installation id, so the OAuth callback can verify the
     * round-trip. The token returned is what concrete connectors
     * include in their authorization URLs (`?state=...`).
     */
    protected function issueOAuthState(int $installationId): string
    {
        $token = Str::random(32);
        $ttl = (int) config('connectors.oauth_state_ttl_seconds', 600);

        Cache::put(
            $this->stateCacheKey($installationId, $token),
            [
                'tenant_id' => $this->tenantContext->current(),
                'installation_id' => $installationId,
                'issued_at' => Carbon::now()->toIso8601String(),
            ],
            $ttl,
        );

        return $token;
    }

    /**
     * Verify + consume a state token. Returns true if the token
     * matches a cached entry for this installation; false otherwise.
     * Single-use: the cache entry is removed before this returns so
     * a replay of the same `state=...` returns false on the second
     * call.
     */
    protected function consumeOAuthState(int $installationId, string $token): bool
    {
        $key = $this->stateCacheKey($installationId, $token);
        $entry = Cache::get($key);
        if ($entry === null) {
            return false;
        }

        Cache::forget($key);

        if (! is_array($entry)) {
            return false;
        }

        if (($entry['installation_id'] ?? null) !== $installationId) {
            return false;
        }

        // Defence in depth: also verify the tenant matches. Cross-
        // tenant state replay is otherwise structurally prevented by
        // the controller's tenant-scoped installation lookup, but
        // the cache is process-wide so a misbehaving connector
        // could in theory cross-pollinate. Belt + braces.
        if (($entry['tenant_id'] ?? null) !== $this->tenantContext->current()) {
            return false;
        }

        return true;
    }

    /**
     * Locate the active installation by id, scoped to the active
     * tenant. Throws `InvalidArgumentException` if the installation
     * doesn't exist or belongs to a different tenant — the
     * framework uses this as a defence-in-depth check inside
     * concrete connectors, even though the controller / job layer
     * already validates tenant scope before calling in.
     */
    protected function loadInstallation(int $installationId): ConnectorInstallation
    {
        $installation = ConnectorInstallation::query()
            ->where('id', $installationId)
            ->where('tenant_id', $this->tenantContext->current())
            ->where('connector_name', $this->key())
            ->first();

        if ($installation === null) {
            throw new \InvalidArgumentException(
                "Connector installation {$installationId} not found for connector '{$this->key()}' in tenant '{$this->tenantContext->current()}'."
            );
        }

        return $installation;
    }

    private function stateCacheKey(int $installationId, string $token): string
    {
        return "connector:oauth_state:{$installationId}:{$token}";
    }
}
