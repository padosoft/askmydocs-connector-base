<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorBase\Support\Metadata;

/**
 * Builds the rich-metadata envelope every connector hands to the host
 * ingest pipeline so source-aware chunkers and rerankers can do their
 * job downstream.
 *
 * Two slots:
 *
 *   - `metadata.converter_hints.<source>` carries the raw vendor-
 *     namespaced bag (notion: { database_id, properties: {...} },
 *     confluence: { space_key, labels: [...], version, ancestor_titles
 *     }, ...). A host ingestor lifts the whole bag into its converted-
 *     document representation so chunkers can read it.
 *
 *   - `metadata.converter_hints._derived` carries normalised reranker-
 *     facing signals (search_tags, status_active, recency_bucket,
 *     owner). Always present — connectors fill what they have; missing
 *     signals safely degrade to no-boost at retrieval time.
 *
 * The wrapper is stateless and deterministic so connectors call it
 * inline at write-time without DI gymnastics. {@see RecencyBucketer}
 * is default-instantiated for the same reason; tests can swap it.
 */
final class SourceAwareMetadataBuilder
{
    private RecencyBucketer $recency;

    public function __construct(?RecencyBucketer $recency = null)
    {
        $this->recency = $recency ?? new RecencyBucketer;
    }

    /**
     * Build the full `metadata` array a connector passes to the host
     * ingest pipeline.
     *
     * @param  array<string,mixed>  $base  Connector-level metadata (connector key, installation_id, ...).
     * @param  string  $sourceKey  Per-source namespace key (e.g. 'notion', 'google_drive').
     * @param  array<string,mixed>  $sourceFields  Raw vendor-shaped fields to publish under that namespace.
     * @param  list<string>  $tags  Search tags lifted by the connector.
     * @param  bool|null  $statusActive  Whether the source row is currently "active". Null degrades to false.
     * @param  mixed  $lastModified  Source's last-modified timestamp (string/DateTimeInterface).
     * @param  string|null  $owner  Single-owner email (or null when shared/unknown).
     * @return array<string,mixed>
     */
    public function build(
        array $base,
        string $sourceKey,
        array $sourceFields,
        array $tags = [],
        ?bool $statusActive = null,
        mixed $lastModified = null,
        ?string $owner = null,
    ): array {
        $cleanTags = array_values(array_unique(array_filter(
            array_map(static fn ($t) => is_string($t) ? trim($t) : '', $tags),
            static fn (string $t): bool => $t !== '',
        )));

        $derived = [
            'search_tags' => $cleanTags,
            'status_active' => (bool) $statusActive,
            'recency_bucket' => $this->recency->bucket($lastModified),
            'owner' => $owner,
        ];

        $hints = [
            $sourceKey => $sourceFields,
            '_derived' => $derived,
        ];

        return array_merge($base, [
            'converter_hints' => $hints,
            // Flat-projected `_derived` mirrors the hints map so any
            // downstream reader that bypasses `converter_hints` (legacy
            // shape, future jobs that take a `_derived` arg directly)
            // still resolves the same signals.
            '_derived' => $derived,
        ]);
    }
}
