<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorBase\Contracts;

use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;

/**
 * Inversion-of-control bridge between connector packages and the host
 * application's ingest pipeline.
 *
 * Per-connector OS packages (e.g. `padosoft/askmydocs-connector-notion`)
 * stay standalone-agnostic — they do NOT depend on AskMyDocs internals
 * (no `App\Jobs\IngestDocumentJob`, no `App\Models\KnowledgeDocument`,
 * no `App\Services\Kb\DocumentDeleter`). Instead they call into this
 * contract, which the host application implements once.
 *
 * AskMyDocs ships the canonical implementation in its `app/Connectors/`
 * tree (`HostIngestionBridge`) — it dispatches `IngestDocumentJob`,
 * persists to the KB disk via `KbPath`, writes audit rows into
 * `kb_canonical_audit`, applies PII redaction via
 * `padosoft/laravel-pii-redactor`, and routes deletions through
 * `DocumentDeleter`.
 *
 * Downstream consumers (any Laravel app that composer-requires a
 * connector package) bind their own implementation under
 * `ConnectorIngestionContract::class` in the container. The package
 * defaults to {@see NullConnectorIngestionContract} which throws a
 * descriptive error so the boot failure surfaces loudly.
 *
 * Five responsibilities:
 *
 *   1. {@see dispatchIngestion()} — hand a freshly-written document off
 *      to the host's ingest pipeline (typically a queued job).
 *   2. {@see resolveKbSourcePath()} — translate a relative path into
 *      the disk + absolute path the connector needs for the actual
 *      `Storage::put()` call. Mirrors `KbPath::normalize()` semantics.
 *   3. {@see redactContent()} — PII redaction at the ingest boundary
 *      (R26). No-op when redaction is disabled.
 *   4. {@see emitAudit()} — write an immutable audit row for the admin
 *      log viewer (install / sync_completed / disconnect / etc.).
 *   5. {@see softDeleteByRemoteId()} — route a provider-side deletion
 *      event (Notion `archived: true`, Drive `removed: true`) to the
 *      host's actual document deletion service, scoped to the
 *      installation's tenant.
 *
 * Every method is invoked on the active tenant — the connector calling
 * in has already resolved `TenantContext` per the framework contract.
 */
interface ConnectorIngestionContract
{
    /**
     * Hand the freshly-written document off to the host's ingest
     * pipeline. Typically dispatches a queued job.
     *
     * @param  string  $projectKey     KB project / tenant bucket the doc belongs to.
     * @param  string  $relativePath   UN-prefixed relative path on the KB disk.
     * @param  string  $disk           Laravel filesystem disk name (matches `resolveKbSourcePath`'s output).
     * @param  string  $title          Human-readable title (Notion page title, Drive file name, ...).
     * @param  array<string,mixed>  $metadata  Source-aware metadata bag (built via {@see Support\Metadata\SourceAwareMetadataBuilder}).
     * @param  string  $mimeType       Effective MIME (potentially a synthetic vendor mime — see {@see Support\Metadata\VendorMimeSelector}).
     * @param  string  $tenantId       The installation's tenant (R30 — explicit pass so the job can restore tenant context inside the worker).
     */
    public function dispatchIngestion(
        string $projectKey,
        string $relativePath,
        string $disk,
        string $title,
        array $metadata,
        string $mimeType,
        string $tenantId,
    ): void;

    /**
     * Translate a relative path into the disk + absolute path the
     * connector uses for `Storage::put()`. The relative form is what
     * the host's ingest job reads back; the absolute form is what
     * `Storage::put()` writes (the host may apply a path prefix
     * configured via `kb.sources.path_prefix`).
     *
     * @return array{relative: string, absolute: string, disk: string}
     */
    public function resolveKbSourcePath(string $relativePath): array;

    /**
     * Apply the host's PII redaction strategy to a document body BEFORE
     * the bytes hit the KB disk. R26 — connectors call this on every
     * fetched document body. Hosts with no redaction configured
     * return the input unchanged.
     */
    public function redactContent(string $content): string;

    /**
     * Emit an immutable audit row for the admin log viewer. The
     * event type is namespaced with `connector_` automatically by the
     * host implementation if not already prefixed.
     *
     * @param  string  $connectorKey   {@see ConnectorInterface::key()}.
     * @param  string  $eventType      One of `installed | sync_completed | sync_failed | disconnected | token_refreshed | …`.
     * @param  int|null  $installationId  Defence-in-depth scoping.
     * @param  array<string,mixed>|null  $metadata  Free-form payload (mode, since, document counts, ...).
     */
    public function emitAudit(
        string $connectorKey,
        string $eventType,
        ?int $installationId = null,
        ?array $metadata = null,
    ): void;

    /**
     * Route a provider-side deletion event to the host's document
     * deletion service. The connector stashes a stable remote-id
     * (Drive `fileId`, Notion `page_id`, OneDrive `driveItemId`) in
     * `knowledge_documents.metadata` at ingest time under a
     * connector-specific key. On deletion the host looks up the matching
     * documents (tenant-scoped) and routes through the
     * deletion-with-trash path.
     *
     * Returns true if at least one row was acted upon, false when no
     * matching document was found. Already-soft-deleted rows are
     * skipped so repeated incremental sweeps don't double-count.
     */
    public function softDeleteByRemoteId(
        ConnectorInstallation $installation,
        string $metadataKey,
        string $remoteId,
    ): bool;
}
