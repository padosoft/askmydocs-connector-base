<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorBase\Contracts;

use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;

/**
 * Default fail-loud implementation of {@see ConnectorIngestionContract}.
 *
 * The base service provider binds this implementation only as a fallback
 * so a host application that forgets to register its own bridge sees a
 * descriptive failure at the first connector call rather than a silent
 * "documents disappear" bug.
 *
 * Hosts MUST bind a real implementation (AskMyDocs ships
 * `App\Connectors\Ingestion\HostIngestionBridge`) under
 * `ConnectorIngestionContract::class` in a service provider.
 *
 * The {@see resolveKbSourcePath()} method has a sensible standalone
 * default: it normalises the relative path and pretends the host has
 * no prefix configured. This lets package tests exercise the full
 * connector flow without a host binding.
 */
final class NullConnectorIngestionContract implements ConnectorIngestionContract
{
    public function dispatchIngestion(
        string $projectKey,
        string $relativePath,
        string $disk,
        string $title,
        array $metadata,
        string $mimeType,
        string $tenantId,
    ): void {
        throw new \LogicException(
            'No ConnectorIngestionContract implementation bound. '
            .'Host applications MUST bind one (e.g. '
            .'`$this->app->bind(ConnectorIngestionContract::class, HostIngestionBridge::class);` '
            .'in a ServiceProvider) — the connector packages cannot dispatch '
            .'ingestion without it.'
        );
    }

    public function resolveKbSourcePath(string $relativePath): array
    {
        // Normalise the path the same way the host bridge would. The
        // disk defaults to 'kb' for parity with the AskMyDocs host;
        // tests that need a different disk should bind their own bridge.
        $normalised = ltrim(str_replace('\\', '/', $relativePath), '/');

        return [
            'relative' => $normalised,
            'absolute' => $normalised,
            'disk' => 'kb',
        ];
    }

    public function redactContent(string $content): string
    {
        // No-op default. Hosts with PII redaction enabled wire the
        // real redactor here.
        return $content;
    }

    public function emitAudit(
        string $connectorKey,
        string $eventType,
        ?int $installationId = null,
        ?array $metadata = null,
    ): void {
        // No-op default. Production hosts persist an audit row.
    }

    public function softDeleteByRemoteId(
        ConnectorInstallation $installation,
        string $metadataKey,
        string $remoteId,
    ): bool {
        // No-op default — no host means no documents to delete.
        return false;
    }
}
