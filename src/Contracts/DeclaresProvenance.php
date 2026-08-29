<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorBase\Contracts;

use Padosoft\AskMyDocsConnectorBase\ProvenanceTier;

/**
 * Optional capability interface for a connector that knows where the
 * authority of the content it ingests comes from.
 *
 * A connector implementing this interface tells the host who authored what it
 * fetches, so the host can record the fact per document instead of treating
 * every ingested byte as equally trustworthy. The host:
 *   1. Detects the interface via instanceof (R23 — never an
 *      `if ($name === 'imap')` branch).
 *   2. Calls provenanceTier() and stores the result alongside the document.
 *   3. Surfaces it — "how much of our corpus is externally authored?" is a
 *      question no deployment can answer today.
 *
 * Implementing it is entirely OPT-IN and backward compatible. A connector that
 * does not implement it keeps its current meaning: the host falls back to
 * {@see ProvenanceTier::default()}, which is the tier every pre-existing
 * connector already carried in practice. Nothing about the ingestion contract
 * changes, and no third-party connector built on the public template breaks.
 *
 * The connector declares this, not the host, because only the connector knows
 * what it is reading from. Two installations of the same connector routinely
 * differ — an internal distribution list and a public contact address are the
 * same IMAP code against sources with opposite authorship models — so the tier
 * is resolved PER INSTALLATION, exactly like
 * {@see SupportsFolderDiscovery::listAvailableFolders()}. `ConnectorRegistry`
 * keeps one connector instance per connector key, so an implementation has no
 * other way to know which installation it is answering for; a zero-argument
 * method would force ambient mutable state that is not set when the host
 * resolves the tier later on the ingestion path.
 *
 * @see ProvenanceTier for why this is NOT a curation tier
 */
interface DeclaresProvenance
{
    /**
     * The tier the content ingested by this installation should carry.
     *
     * MUST be side-effect free: it is called on the ingestion path, per
     * document, and must not perform network calls or mutate configuration.
     * A connector whose tier depends on installation configuration should
     * read the stored configuration, not re-fetch it upstream.
     *
     * @param  int  $installationId  The `connector_installations` row being
     *                               ingested for. Resolve it through the
     *                               connector's own tenant-scoped lookup, as
     *                               the other installation-scoped capabilities do.
     */
    public function provenanceTier(int $installationId): ProvenanceTier;
}
