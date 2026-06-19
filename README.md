<h1 align="center">askmydocs-connector-base</h1>

<p align="center">
  <strong>Framework primitives for AskMyDocs connectors — write a Laravel package, plug it into any AskMyDocs instance.</strong><br/>
  Implement <code>ConnectorInterface</code> on your favourite data source (Google Drive, Notion, Confluence, a CSV bucket, an internal API, ...) and let AskMyDocs ingest it as RAG-grounded knowledge with OAuth, encrypted-at-rest credentials, retry-aware queued syncs, per-tenant isolation, and a cadence scheduler — all wired automatically by composer discovery.
</p>

<p align="center">
  <a href="https://github.com/padosoft/askmydocs-connector-base/actions/workflows/tests.yml"><img alt="CI status" src="https://img.shields.io/github/actions/workflow/status/padosoft/askmydocs-connector-base/tests.yml?branch=main&label=tests"></a>
  <a href="https://packagist.org/packages/padosoft/askmydocs-connector-base"><img alt="Packagist version" src="https://img.shields.io/packagist/v/padosoft/askmydocs-connector-base.svg?label=packagist"></a>
  <a href="https://packagist.org/packages/padosoft/askmydocs-connector-base"><img alt="Total downloads" src="https://img.shields.io/packagist/dt/padosoft/askmydocs-connector-base.svg?label=downloads"></a>
  <a href="LICENSE"><img alt="License" src="https://img.shields.io/badge/license-Apache--2.0-blue.svg"></a>
  <img alt="PHP version" src="https://img.shields.io/badge/php-8.3%20%7C%208.4%20%7C%208.5-777BB4">
  <img alt="Laravel version" src="https://img.shields.io/badge/laravel-12%20%7C%2013-FF2D20">
</p>

---

## Table of contents

1. [Why this package](#why-this-package)
2. [What you get](#what-you-get)
3. [Architecture at a glance](#architecture-at-a-glance)
4. [Installation](#installation)
5. [Quick start — write your first connector in 50 lines](#quick-start--write-your-first-connector-in-50-lines)
6. [The 10-method contract](#the-10-method-contract)
7. [Optional: credential form interface](#optional-credential-form-interface)
8. [How auto-discovery works](#how-auto-discovery-works)
9. [Credential vault — encrypted, atomic, tenant-scoped](#credential-vault--encrypted-atomic-tenant-scoped)
10. [Scheduler + sync job](#scheduler--sync-job)
11. [Multi-tenancy (R30 + R31)](#multi-tenancy-r30--r31)
12. [Configuration reference](#configuration-reference)
13. [Testing](#testing)
14. [Roadmap](#roadmap)
15. [License](#license)

---

## Why this package

[AskMyDocs](https://github.com/lopadova/AskMyDocs) is an enterprise-grade RAG + canonical knowledge compilation system. Out of the box it ingests markdown from disk, the chat UI, an HTTP API, and a Git-driven workflow.

But the knowledge people actually want to query lives in **Google Drive, Notion, Confluence, Jira, OneDrive, Evernote, Fabric, Slack, Salesforce, HubSpot, a private S3 bucket, a custom CRM** — anywhere except markdown-on-disk.

This package is **the smallest possible surface** for shipping a new connector:

- A 10-method `ConnectorInterface` you implement.
- A `BaseConnector` that gives you OAuth state-token CSRF, credential refresh, and tenant-scoped installation lookup for free.
- A registry that auto-discovers your package the moment somebody `composer require`s it — zero config edits on the consumer side.
- An `OAuthCredentialVault` that handles encryption-at-rest, refresh-token semantics, and **atomic concurrent writes** (no read-modify-write data loss on shared cursor blobs).
- A queued `ConnectorSyncJob` with exponential backoff, tenant restore, and failure-state recording.
- A cadence scheduler that walks active installations every minute and dispatches due syncs.
- Per-tenant isolation baked into every query (R30 / R31 — see [Multi-tenancy](#multi-tenancy-r30--r31)).

> **Write the connector. Ship the package. Composer-require it from any AskMyDocs install. Done.**

## What you get

| Surface | Class | What it does |
|---|---|---|
| Contract | `ConnectorInterface` | 10 methods every connector implements |
| Base | `BaseConnector` | OAuth state-token CSRF, refresh helper, tenant lookup |
| Registry | `ConnectorRegistry` | Boot-time R23 validation + composer-extra auto-discovery |
| Vault | `Auth\OAuthCredentialVault` | AES-encrypted tokens, atomic `setExtraKey` (R21), tenant scope |
| Scheduler | `Scheduling\SyncScheduler` | Cadence walker, `chunkById(100)`, active-only filter |
| Job | `ConnectorSyncJob` | `$tries=3`, exponential backoff, tenant-restore safety |
| Models | `ConnectorInstallation` + `ConnectorCredential` | `BelongsToTenant` trait, cascade delete |
| Migrations | `connector_installations` + `connector_credentials` | Auto-loaded by the service provider |
| Exceptions | `ConnectorAuthException`, `ConnectorApiException`, `ConnectorPaginationLimitException`, `RegistryConfigurationException` | Distinct failure semantics: auth = no retry, api = retry, paginator-limit = partial success |
| DTOs | `SyncResult`, `HealthStatus` | Immutable outcomes |
| Tenancy | `Support\TenantContext` + `Models\Concerns\BelongsToTenant` | Request-scoped tenant, auto-fill on creating |
| Credential form _(optional)_ | `Contracts\SupportsCredentialForm` + `Support\CredentialField` | Opt-in interface for credential-based connectors (IMAP, API key, …) — host renders a native admin form instead of OAuth redirect |

## Architecture at a glance

```
                         ┌──────────────────────────┐
                         │  Your connector package  │
                         │  composer extra.askmydocs│
                         └─────────────┬────────────┘
                                       │  auto-discovered
                                       ▼
┌────────────────────┐         ┌──────────────────┐         ┌──────────────────────────┐
│ Cadence scheduler  │ ──────▶ │ ConnectorRegistry│ ◀────── │ Host: config/connectors  │
│ (every minute)     │         │ R23 boot-time    │         │ ::built_in (optional)    │
└────────┬───────────┘         │ FQCN validation  │         └──────────────────────────┘
         │                     └─────────┬────────┘
         │ dispatch                      │ resolve by key()
         ▼                               ▼
┌────────────────────┐         ┌──────────────────┐         ┌──────────────────────────┐
│ ConnectorSyncJob   │ ──────▶ │ Your connector   │ ──────▶ │ OAuthCredentialVault     │
│ tenant-restore     │         │ syncIncremental()│         │ AES + lockForUpdate (R21)│
│ tries=3, backoff   │         └─────────┬────────┘         └──────────────────────────┘
└────────────────────┘                   │
                                         │ fetches changed docs
                                         ▼
                              ┌────────────────────────┐
                              │ Host ingest pipeline   │
                              │ (e.g. AskMyDocs        │
                              │  IngestDocumentJob)    │
                              └────────────────────────┘
```

## Installation

```bash
composer require padosoft/askmydocs-connector-base
```

The service provider is auto-discovered (Laravel package discovery). The package ships its own migrations — run them:

```bash
php artisan migrate
```

Want to copy the migrations into your host app's `database/migrations/` (e.g. to tweak `tenant_id` length)? Publish them:

```bash
php artisan vendor:publish --tag=connector-migrations
```

Same for the config:

```bash
php artisan vendor:publish --tag=connector-config
```

Wire the scheduler from your host app's `bootstrap/app.php`:

```php
use Padosoft\AskMyDocsConnectorBase\Scheduling\SyncScheduler;

->withSchedule(function (Schedule $schedule): void {
    (new SyncScheduler)->registerSchedules($schedule);
})
```

That's it. Connector packages installed via composer are now auto-discovered and synced on cadence.

## Quick start — write your first connector in 50 lines

Create a new Laravel package. Add `padosoft/askmydocs-connector-base` to its `require`. Declare your connector class FQCN under `extra.askmydocs.connectors`:

```jsonc
// composer.json (your package)
{
    "name": "you/askmydocs-connector-myapi",
    "require": {
        "padosoft/askmydocs-connector-base": "^1.0"
    },
    "autoload": {
        "psr-4": { "You\\AskMyDocsConnectorMyApi\\": "src/" }
    },
    "extra": {
        "askmydocs": {
            "connectors": [
                "You\\AskMyDocsConnectorMyApi\\MyApiConnector"
            ]
        }
    }
}
```

Implement the connector:

```php
namespace You\AskMyDocsConnectorMyApi;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Padosoft\AskMyDocsConnectorBase\BaseConnector;
use Padosoft\AskMyDocsConnectorBase\HealthStatus;
use Padosoft\AskMyDocsConnectorBase\SyncResult;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorAuthException;

final class MyApiConnector extends BaseConnector
{
    public function key(): string         { return 'my-api'; }
    public function displayName(): string { return 'My API'; }

    public function oauthScopes(): array
    {
        return ['read:docs'];
    }

    public function initiateOAuth(int $installationId): string
    {
        $state = $this->issueOAuthState($installationId);
        return 'https://my-api.example.com/oauth/authorize?state='.$state.'&...';
    }

    public function handleOAuthCallback(int $installationId, Request $request): void
    {
        if (! $this->consumeOAuthState($installationId, (string) $request->query('state'))) {
            throw new ConnectorAuthException('Bad state');
        }
        // Exchange code -> token, then:
        $this->vault->setCredentials($installationId, 'access-token', refreshToken: 'refresh');
    }

    public function syncFull(int $installationId): SyncResult
    {
        return $this->syncIncremental($installationId, null);
    }

    public function syncIncremental(int $installationId, ?Carbon $since): SyncResult
    {
        // Fetch changed docs, dispatch host ingest jobs, count them.
        return new SyncResult(
            documentsAdded: 5,
            documentsUpdated: 2,
            documentsRemoved: 0,
            errors: [],
            completedAt: Carbon::now(),
        );
    }

    public function disconnect(int $installationId): void
    {
        $this->vault->clearCredentials($installationId);
    }

    public function health(int $installationId): HealthStatus
    {
        return HealthStatus::healthy();
    }
}
```

`composer require you/askmydocs-connector-myapi` in any AskMyDocs install — the registry auto-discovers it, the scheduler starts dispatching it on cadence, the admin UI lists it in the available-connectors picker.

## The 10-method contract

Every connector implements 10 methods (3 metadata + 1 scope + 2 OAuth + 2 sync + 1 disconnect + 1 health):

| Method | Purpose | Throws |
|---|---|---|
| `key(): string` | Stable kebab-case identifier (`google-drive`, `notion`). Used as URL slug + `connector_installations.connector_name`. | — |
| `displayName(): string` | Human label shown in the admin UI. | — |
| `iconUrl(): string` | Connector logo URL. `BaseConnector` provides a default that resolves `public/connectors/{key}.svg` via `asset()`. | — |
| `oauthScopes(): array` | List of scope strings the provider requires. Surfaced to the user in the install confirmation dialog. | — |
| `initiateOAuth(int): string` | Build the provider's authorization URL. Use `$this->issueOAuthState()` for CSRF. | `ConnectorAuthException` |
| `handleOAuthCallback(int, Request): void` | Exchange auth code -> tokens, verify state, persist via `$this->vault->setCredentials()`. | `ConnectorAuthException` on any failure |
| `syncFull(int): SyncResult` | Full discovery + ingestion. Long-running. Called at install + operator re-sync. | propagates upstream errors |
| `syncIncremental(int, ?Carbon): SyncResult` | Delta since `$since`. Falls back to `syncFull` when `$since === null`. Called by the cadence scheduler. | `ConnectorApiException` for transient (retry), `ConnectorAuthException` for credentials (no retry) |
| `disconnect(int): void` | Clear credentials, optionally revoke at provider. | swallow / log; framework deletes installation row after |
| `health(int): HealthStatus` | Fast (under 2s) side-effect-free probe. | returns `HealthStatus::errored(...)` instead of throwing |

## Optional: credential form interface

For connectors that use **credentials instead of OAuth** (IMAP, SMTP, API-key-based providers, ...), implement the optional `SupportsCredentialForm` interface alongside `ConnectorInterface`.

The host detects the interface via `instanceof` at install time and renders a native admin form. Each field is described by a `CredentialField` value object — call `toArray()` on each to produce the JSON shape the host expects.

```php
use Padosoft\AskMyDocsConnectorBase\Contracts\SupportsCredentialForm;
use Padosoft\AskMyDocsConnectorBase\Support\CredentialField;

final class ImapConnector extends BaseConnector implements SupportsCredentialForm
{
    public function credentialFormSchema(): array
    {
        return [
            (new CredentialField(
                name: 'host', label: 'IMAP Host', type: 'text', target: 'connection', required: true,
            ))->toArray(),
            (new CredentialField(
                name: 'port', label: 'Port', type: 'number', target: 'connection', required: true, default: 993,
            ))->toArray(),
            (new CredentialField(
                name: 'username', label: 'Username', type: 'text', target: 'connection', required: true,
            ))->toArray(),
            (new CredentialField(
                name: 'password', label: 'Password', type: 'password', target: 'secret',
                required: true, secret: true,  // routed to vault, never stored in config_json
            ))->toArray(),
        ];
    }
}
```

**`CredentialField` properties:**

| Property | Type | Description |
|---|---|---|
| `name` | `string` | Form-data key (e.g. `'host'`) |
| `label` | `string` | Human-readable UI label |
| `type` | `string` | `text` \| `number` \| `password` \| `select` \| `checkbox` |
| `target` | `string` | `connection` \| `config` → `config_json`; `auth_mode`; `provider`; `secret` → vault |
| `required` | `bool` | Whether the field must be filled |
| `secret` | `bool` | Masked in UI; routed to vault, never `config_json` |
| `default` | `mixed` | Pre-filled value |
| `options` | `array<string,string>` | For `select`: `['value' => 'Label']` |
| `showIf` | `array{field:string,equals:string}\|null` | Conditional display rule |
| `help` | `string\|null` | Helper text rendered below the field |
| `group` | `string\|null` | Optional UI section heading |

Connectors that use only the standard **OAuth redirect** do **not** implement this interface — it is entirely opt-in and backward compatible.

## How auto-discovery works

`ConnectorRegistry` merges two sources at boot:

1. **`config/connectors.php::built_in`** — FQCN list for connectors the host app wires by hand (rare).
2. **`composer.lock` packages** — every entry whose `extra.askmydocs.connectors` is a non-empty array of FQCNs.

Each FQCN is resolved through the container and `instanceof`-checked against `ConnectorInterface` (R23). Failure modes:

- Class missing -> `RegistryConfigurationException: '...' does not exist`
- Class exists but doesn't implement -> `RegistryConfigurationException: '...' does not implement ConnectorInterface`
- Two connectors return the same `key()` -> `RegistryConfigurationException: Duplicate connector key '...'`
- Container can't instantiate -> `RegistryConfigurationException: '...' could not be resolved`

All boot-time. No silent fallthrough to a confusing "undefined method" later.

## Credential vault — encrypted, atomic, tenant-scoped

`OAuthCredentialVault` is the single chokepoint for every connector's tokens:

- **AES-256 encryption at rest** via Laravel `Crypt`. The DB row never sees plaintext.
- **Tenant-scoped reads** — every query joins to `connector_installations` and filters by the active `TenantContext`. Cross-tenant reads return `null`, not the wrong tenant's tokens.
- **Refresh-aware** — `getAccessToken()` returns `null` for expired tokens. Connectors call `getRefreshToken()` to rotate via the provider's `/oauth2/token` endpoint, then `setCredentials()` to persist the rotated pair.
- **R21 — atomic `setExtraKey`** — concurrent writers updating different keys in `extra_json` (e.g. one connector storing `bot_id`, another storing `changes_page_token`) MUST NOT race. Implementation:

```php
DB::transaction(function () use (...) {
    $row = ConnectorCredential::query()
        ->where(...)
        ->lockForUpdate()  // SELECT ... FOR UPDATE
        ->first();

    if ($row === null) {
        throw new ConnectorAuthException('credential row was deleted concurrently');
    }

    $extra = $row->extra_json ?? [];
    $extra[$key] = $value;
    $row->extra_json = $extra;
    $row->save();  // same transaction
});
```

A read-modify-write without the lock loses siblings under contention. The package was extracted from AskMyDocs precisely after this race was caught + fixed in production.

## Scheduler + sync job

`SyncScheduler::registerSchedules($schedule)` registers one `everyMinute()` closure. The closure walks every `STATUS_ACTIVE` installation in `chunkById(100)` and dispatches `ConnectorSyncJob` for each that's due (i.e. `last_sync_at + cadenceMinutes <= now()`).

`ConnectorSyncJob`:

- `$tries = 3`, `$backoff = [60, 300, 900]` — three attempts at 1m / 5m / 15m spacing.
- `$timeout = 600` — 10 min hard ceiling.
- **Tenant restore in `finally`** — the job sets `TenantContext` to the dispatching tenant on entry, restores the prior value on exit. Long-lived queue workers handling jobs back-to-back for different tenants are R30-safe.
- **Status guards** — non-`ACTIVE` installations short-circuit. De-registered connectors flip to `STATUS_ERRORED` with a clear message.
- **Failure semantics** — `ConnectorAuthException` marks `errored` and exhausts retries (no point retrying bad credentials). `ConnectorApiException` and other throwables fail-and-retry per the backoff.

## Multi-tenancy (R30 + R31)

Every model uses the `BelongsToTenant` trait:

- **R31 (write-side)** — `tenant_id` auto-fills from `TenantContext::current()` on `creating` unless the caller passes an explicit value.
- **R30 (read-side)** — `forTenant($id)` scope for explicit query scoping. Two tenants legitimately install the same connector under different `tenant_id`s — the composite UNIQUE `(tenant_id, connector_name)` makes the row pair structurally legal.

Host applications with their own `TenantContext` rebind via a container alias — both surfaces observe the same active tenant.

## Configuration reference

```php
// config/connectors.php (publishable with --tag=connector-config)
return [
    'built_in' => [
        // \App\Connectors\BuiltIn\MyHostConnector::class,
    ],
    'default_sync_cadence_minutes' => env('CONNECTOR_DEFAULT_SYNC_CADENCE_MINUTES', 15),
    'per_connector_cadence' => [
        // 'google-drive' => 10,
        // 'notion'       => 30,
    ],
    'oauth_state_ttl_seconds' => env('CONNECTOR_OAUTH_STATE_TTL_SECONDS', 600),
    'sync_job_queue' => env('CONNECTOR_SYNC_JOB_QUEUE', 'default'),
    'providers' => [
        // Per-connector packages merge their own block here from their
        // own service providers via mergeConfigFrom().
    ],
];
```

## Testing

```bash
composer install
vendor/bin/phpunit
```

Tests use [Orchestra Testbench](https://github.com/orchestral/testbench) with SQLite in-memory. The default suite has zero external dependencies — every Laravel facade is in scope, every `Crypt::encryptString()` call uses a per-test `APP_KEY`, every model uses `RefreshDatabase`.

For connector packages built ON TOP of this base, follow the standard padosoft testing pattern: a default `tests/Unit/` suite that uses `Http::fake()` (zero cost, runs in CI), plus an opt-in `tests/Live/` suite that hits the real provider API (skipped when the env var is missing, invoked explicitly by maintainers).

## Roadmap

- **v1.1** — Optional `ChunkerInterface` re-export once the AskMyDocs chunker value-object surface stabilises, so per-connector packages can ship provider-specific chunkers (already used in AskMyDocs for `ConfluencePageChunker`, `JiraIssueChunker`, `AtomicNoteChunker`).
- **v1.2** — Optional admin-trail helpers (audit event emission, PII redaction at the ingest boundary) lifted from AskMyDocs' host-side `BaseConnector` subclass into an `AuditableBaseConnector` mixin for hosts that want them out of the box.
- **v2.0** — `MCPConnectorInterface` companion for chat-time tool registration (Model Context Protocol). Connectors register tools the agent calls during a chat turn, complementing today's batch-sync model. Tracks the v4.5+ AskMyDocs agentic roadmap.

Community PRs welcome — open an issue first to discuss scope.

## License

Apache-2.0 (c) Padosoft / Lorenzo Padovani. See [LICENSE](LICENSE).
