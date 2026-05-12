# Changelog

All notable changes to `padosoft/askmydocs-connector-base` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## v1.0.0 — Initial release (2026-05-12)

Initial extraction from the AskMyDocs v4.5 inline connector framework into a standalone, reusable Laravel package.

### Added

- `ConnectorInterface` — 10-method contract every external-source connector implements (`key`, `displayName`, `iconUrl`, `oauthScopes`, `initiateOAuth`, `handleOAuthCallback`, `syncFull`, `syncIncremental`, `disconnect`, `health`).
- `BaseConnector` — abstract base class providing CSRF-safe OAuth state-token round-trip (`issueOAuthState` / `consumeOAuthState`), default `refreshTokenIfExpired` no-op, default `iconUrl` resolution via `asset()`, and tenant-scoped `loadInstallation` lookup.
- `ConnectorRegistry` — boot-time FQCN validation (R23) + auto-discovery from two channels: `config/connectors.php::built_in` and `composer.lock` package `extra.askmydocs.connectors` arrays. Throws `RegistryConfigurationException` on invalid FQCN, missing class, non-implementor, or duplicate key.
- `Auth\OAuthCredentialVault` — encrypted-at-rest credential store with R21 atomic `setExtraKey()` (uses `DB::transaction` + `lockForUpdate` so concurrent writers cannot lose siblings in the `extra_json` blob). Refuses to recreate the row on concurrent disconnect.
- `Scheduling\SyncScheduler` — minute-grain cadence walker. Memory-safe via `chunkById(100)`. Filters to `STATUS_ACTIVE` rows. Respects per-connector overrides.
- `ConnectorSyncJob` — queued, retry-aware (`$tries=3`, `$backoff=[60,300,900]`, `$timeout=600`). Captures + restores `TenantContext` across job invocations to prevent cross-tenant leak in long-lived workers.
- Exceptions: `ConnectorAuthException` (4xx auth), `ConnectorApiException` (5xx / transient), `ConnectorPaginationLimitException` (paginator truncation), `RegistryConfigurationException` (boot-time misconfig).
- Models: `ConnectorInstallation` + `ConnectorCredential` with `BelongsToTenant` trait (auto-fill `tenant_id` on `creating`; `forTenant($id)` scope for explicit query scoping). Cascade `ON DELETE` on installation → credential.
- Support: `TenantContext` (request-scoped singleton, default `'default'`, format `/^[a-z0-9_-]{1,50}$/`). Host applications with their own `TenantContext` rebind via the container.
- Migrations: `connector_installations` (composite UNIQUE on `(tenant_id, connector_name)`; status `pending|active|disabled|errored`) + `connector_credentials` (one-to-one with installation, AES-encrypted access + refresh tokens, opaque `extra_json` blob).
- `ConnectorServiceProvider` — Laravel auto-discovery, publishes migrations under `connector-migrations` tag and config under `connector-config` tag. Auto-loads migrations from package by default.
- Default `config/connectors.php` with sane defaults: `default_sync_cadence_minutes=15`, `oauth_state_ttl_seconds=600`, `sync_job_queue=default`. Empty `built_in` and `providers` arrays — per-connector packages contribute their own entries via `mergeConfigFrom()` from their service providers.

### Decisions

- **`BelongsToTenant` trait + `TenantContext` lifted into the package.** Both are pure code with zero AskMyDocs dependencies. Host applications that already ship their own equivalent classes rebind the package's class via a container alias.
- **`ChunkerInterface` NOT extracted in v1.0.** The AskMyDocs interface depends on `ChunkDraft` + `ConvertedDocument` value objects that are themselves tied to the v3 ingestion pipeline. Per-connector packages can re-declare a local chunker interface if needed; promotion is deferred to a future release once the value-object surface stabilises.
- **`BaseConnector` does NOT depend on AskMyDocs internals.** The original AskMyDocs `BaseConnector` ships extra helpers (`emitAudit` writing to `kb_canonical_audit`, `maybeRedactContent` via `padosoft/laravel-pii-redactor`, `softDeleteByMetadataKey` via `DocumentDeleter`, `resolveKbSourcePath` via `KbPath`). Those helpers stay in AskMyDocs — host applications subclass this package's `BaseConnector` once more to layer them on. Per-connector OS packages remain standalone-agnostic.
