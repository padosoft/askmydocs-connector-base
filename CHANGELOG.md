# Changelog

All notable changes to `padosoft/askmydocs-connector-base` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## v1.4.0 — Folder discovery + editable connection-settings capabilities (2026-06-25)

### Added

- **`Contracts\SupportsFolderDiscovery`** — optional capability interface.
  `listAvailableFolders(int $installationId): array` returns the live, verbatim
  container identifiers (IMAP folders, labels, spaces) an operator may
  whitelist/blacklist for sync. The connector owns the auth + client lifecycle
  (token refresh, connect, close) so the host never reconstructs the upstream
  client. Read-only and side-effect-free; an unreachable source / rejected
  credentials throw `ConnectorApiException` (host → 503), never a misleading
  empty-but-successful list (R14). The host detects it via `instanceof`
  (R23 — no connector-name branch).
- **`Contracts\SupportsConnectionSettings`** — optional capability interface.
  `connectionSettingsSchema(): array` declares the EDITABLE post-install
  sync-behaviour knobs (sync window, folders include/exclude, sender/recipient/
  subject filters, body format, attachment limits, …) as a list of
  `CredentialField::toArray()` shapes. Distinct from `SupportsCredentialForm`
  (connect-time credentials): every field uses `target='config'` and is never
  secret. The host renders them in a generic, schema-driven settings editor
  seeded with the installation's current `config_json` — no bespoke per-connector
  form. A field `name` is a dotted path written into `config_json`
  (`folders.include` → `config_json['folders']['include']`), matching exactly what
  the connector reads at sync time.
- **`CredentialField`** gains two field types and one optional property:
  - `type` now also accepts `multiselect` (a list chosen from a fixed or live
    option set) and `tags` (an open-ended free-text string list). Both serialise
    as a JSON array.
  - new optional constructor argument `discovery: ?string = null` — for a live
    `multiselect`, names the discovery source the host queries to populate the
    choices (`'folders'` → `SupportsFolderDiscovery::listAvailableFolders()`).
    Kept a free string (not an enum) so future sources (labels, spaces, drives)
    need no base change.
  - `toArray()` appends `'discovery'` as the 12th key. **Additive** — pre-v1.4
    consumers that read by key are unaffected (it is simply `null` on every
    legacy field).

### Compatibility

- Fully backward compatible. The new interfaces are opt-in; a connector that does
  not implement them is unchanged. The `discovery` constructor argument is the
  last parameter with a `null` default, so existing positional/named `CredentialField`
  constructions are unaffected.

## v1.3.1 — Honour legacy config_json project_key in resolveProjectKey (2026-06-22)

### Fixed

- `BaseConnector::resolveProjectKey()` now falls back to a legacy
  `config_json['project_key']` when the `project_key` column is empty,
  before the host default. Installations created before the v1.3.0
  `project_key` column existed stored the binding in `config_json`; the
  column-only resolution introduced in v1.3.0 would have silently
  re-scoped them to `kb.ingest.default_project`/`default` after upgrade.
  Resolution order is now: column → `config_json['project_key']` →
  `config('kb.ingest.default_project')` → `'default'`. Backward compatible.
- Backfill migration `2026_06_22_000002_backfill_project_key_from_config_json`
  MOVES a non-empty legacy `config_json['project_key']` into the
  `project_key` column (when the column is empty) and removes it from
  `config_json`, so the column becomes the single source of truth — a
  legacy install can then be unbound to the host default by clearing the
  column alone. Idempotent, R3 memory-safe (chunkById), PHP-side JSON
  (portable across SQLite/Postgres/MySQL).

## v1.3.0 — Multi-account + project-scoped installations (2026-06-22)

### Added

- **Multi-account installations.** `connector_installations` gains a `label`
  column (string, default `'default'`) and the composite unique relaxes from
  `(tenant_id, connector_name)` → `(tenant_id, connector_name, label)`. A
  tenant can now connect MORE THAN ONE account per connector — e.g. two IMAP
  mailboxes ("support", "sales"), several Drive/OneDrive accounts, multiple
  Notion workspaces — each disambiguated by its `label`. The unique stays
  tenant-first (R30/R31); two tenants may still share a label.
- **Optional project binding.** `connector_installations.project_key` (string,
  nullable, indexed via `(tenant_id, project_key)`) lets an operator bind an
  account to a real KB project. Empty = inherit the tenant default.
- **`BaseConnector::resolveProjectKey(ConnectorInstallation $i): string`** —
  the single source of truth for the ingest project: the installation's
  explicit `project_key`, else `config('kb.ingest.default_project')`, else the
  literal `default`. Concrete connectors call `$this->resolveProjectKey($installation)`
  instead of re-deriving a `connector-<key>` synthetic project.

### Migration / upgrade notes

- The new migration backfills `label='default'` on every existing row, so
  prior single-account installations are preserved as the canonical default
  account and the relaxed unique is satisfied automatically.
- The migration `down()` drops the additive columns/index/unique but does NOT
  restore the original strict `(tenant_id, connector_name)` unique: once
  multi-account rows exist, re-tightening is impossible without data loss.
  Deduplicate to one account per connector before re-adding it by hand if
  needed.
- Fully backward compatible for hosts that only ever create one installation
  per connector.

## v1.2.0 — Optional SupportsCredentialForm interface + CredentialField value object (2026-06-19)

### Added

- `Contracts\SupportsCredentialForm` — optional capability interface for credential-based connectors (IMAP, API-key, non-OAuth flows). A connector that implements this advertises to the AskMyDocs host that it can be configured via a native admin form instead of an OAuth redirect. The host detects it via `instanceof`, calls `credentialFormSchema()`, and routes submitted values to `config_json`, `auth_mode`, or the encrypted vault according to each field's `target`. Connectors that use only the OAuth redirect flow do NOT implement this interface — fully opt-in and backward compatible.
- `Support\CredentialField` — immutable readonly value object describing a single form field (name, label, type, target, required, secret, default, options, showIf, help, group). `toArray()` returns a plain JSON-serialisable shape for the host. Secret fields (secret:true) are never stored in `config_json` — the host routes them through `handleOAuthCallback()` into the encrypted vault.

### Notes

- Fully backward compatible. Existing connectors that implement `ConnectorInterface` / extend `BaseConnector` are unaffected.

## v1.1.0 — Ingestion contract + source-aware metadata helpers (2026-05-12)

### Added

- `Contracts\ConnectorIngestionContract` — inversion-of-control bridge between connector packages and the host's ingest pipeline. Five responsibilities: `dispatchIngestion()`, `resolveKbSourcePath()`, `redactContent()`, `emitAudit()`, `softDeleteByRemoteId()`. Per-connector OS packages (`padosoft/askmydocs-connector-notion`, `padosoft/askmydocs-connector-google-drive`, ...) call into this contract instead of host-specific classes — keeping them standalone-agnostic.
- `Contracts\NullConnectorIngestionContract` — fail-loud default. Bound automatically by `ConnectorServiceProvider` when the host has not registered its own implementation. `dispatchIngestion()` throws `LogicException` with a descriptive message; `resolveKbSourcePath()` provides a sensible default for package tests; the remaining methods are safe no-ops.
- `Support\Metadata\SourceAwareMetadataBuilder` — builds the rich-metadata envelope every connector hands to the host pipeline. Populates `metadata.converter_hints.<source>` with vendor-shaped fields + `metadata.converter_hints._derived` with normalised reranker-facing signals (search_tags, status_active, recency_bucket, owner). Flat-projects `_derived` at the top level for legacy readers.
- `Support\Metadata\RecencyBucketer` — maps a `last_modified` timestamp to a coarse recency bucket (`this_week | this_month | this_quarter | older`). Stateless and deterministic. Future-dated input is treated as fresh; unparseable input falls back to `older`.
- `Support\Metadata\VendorMimeSelector` — picks the synthetic vendor MIME that routes a connector-produced document to the right source-aware chunker on the host side. Constants for Notion / Drive / Confluence / Jira / Evernote / Fabric / OneDrive + Google-specific and OneDrive-specific selectors.
- `BaseConnector` now constructor-injects a `ConnectorIngestionContract` and exposes proxy helpers (`dispatchIngestion`, `resolveKbSourcePath`, `maybeRedactContent`, `emitAudit`, `softDeleteByMetadataKey`) so concrete connectors stay host-agnostic while still getting the helper shape they had inside AskMyDocs.

### Changed

- `BaseConnector::__construct` now takes a third parameter (`ConnectorIngestionContract`). Existing subclasses constructed via the container pick the binding up automatically; manual `new` callers must update accordingly.

### Notes

- This release is fully backward-compatible at the `ConnectorInterface` level — concrete connectors that did NOT depend on the host helpers continue to work unchanged. The new helpers on `BaseConnector` are additive.

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
