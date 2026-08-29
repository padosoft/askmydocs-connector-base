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
 * what it is reading from. Two installations of the same connector can even
 * differ — an internal distribution list and a public contact address are the
 * same IMAP code against sources with opposite authorship models — which is
 * why the method receives nothing and is free to consult the connector's own
 * installation state if it needs to.
 *
 * @see ProvenanceTier for why this is NOT a curation tier
 */
interface DeclaresProvenance
{
    /**
     * The tier the content this connector ingests should be labelled with.
     *
     * MUST be side-effect free: it is called on the ingestion path, per
     * document, and must not perform network calls or mutate configuration.
     * A connector whose tier depends on installation configuration should
     * read that configuration, not re-fetch it.
     */
    public function provenanceTier(): ProvenanceTier;
}
