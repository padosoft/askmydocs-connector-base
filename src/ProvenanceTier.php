<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorBase;

/**
 * Where the authority of a document's text comes from — who wrote it.
 *
 * A connector reads from a source; the source has an authorship model that
 * ingestion has historically discarded. Most connectors carry text written
 * inside the organisation. An IMAP connector carries text written by anyone
 * who can send an email to the mailbox, and that text becomes retrieval
 * grounding on a platform that also exposes tools an agent can call. The two
 * cases are not the same fact and should not be stored as the same fact.
 *
 * The CONNECTOR declares the tier, never the host. Only the fetcher knows
 * whether a mailbox is an internal distribution list or a public contact
 * address; inferring it host-side would be a heuristic over something the
 * connector already had as a fact.
 *
 * **This is not a curation tier.** A curation ranking answers "has a human
 * vouched for this?"; provenance answers "who wrote it?". A page a human
 * reviewed and accepted, summarising an external email, is fully curated AND
 * externally authored at the same time. Collapsing the two loses the half
 * that matters for trust decisions.
 *
 * The value is a label. Nothing here enforces anything on its own; it exists
 * so enforcement can be built, measured and tested against a corpus that is
 * already labelled.
 */
enum ProvenanceTier: string
{
    /**
     * Written by someone inside the organisation, through a system the
     * organisation controls — a wiki page, an internal drive document, a
     * ticket written by staff.
     */
    case TrustedInternal = 'trusted-internal';

    /**
     * Written by someone outside the organisation's control, or by an
     * unverified author. Inbound email is the canonical case: the sender
     * chooses the content, and nothing about delivery implies authority.
     */
    case UntrustedExternal = 'untrusted-external';

    /**
     * Produced by a model or an automated process rather than a person —
     * a generated summary, a synthesised page, an automated report.
     */
    case MachineGenerated = 'machine-generated';

    /**
     * The tier to assume for a connector that declares nothing.
     *
     * Every connector that existed before this interface carried content
     * authored inside the organisation, so this preserves their meaning
     * exactly. It is deliberately NOT the safest possible value: a default of
     * "untrusted" would relabel the entire existing corpus overnight and make
     * the label meaningless. A connector whose content is external must say
     * so, which is the whole point of {@see Contracts\DeclaresProvenance}.
     */
    public static function default(): self
    {
        return self::TrustedInternal;
    }

    /**
     * Resolve a stored value, falling back to the default for anything this
     * version does not recognise.
     *
     * A row written by a newer version of the package (or by hand) must not
     * crash a reader. Callers that need to distinguish "absent" from
     * "unrecognised" should use {@see tryFrom()} directly.
     */
    public static function fromStorage(?string $value): self
    {
        if ($value === null) {
            return self::default();
        }

        return self::tryFrom($value) ?? self::default();
    }

    /**
     * Whether text at this tier was authored outside the organisation's
     * control.
     *
     * The question enforcement will ask, named once here so callers do not
     * re-derive it from a case comparison and drift apart.
     */
    public function isExternallyAuthored(): bool
    {
        return $this === self::UntrustedExternal;
    }

    /**
     * Short human-readable label for admin surfaces.
     */
    public function label(): string
    {
        return match ($this) {
            self::TrustedInternal => 'Trusted internal',
            self::UntrustedExternal => 'Untrusted external',
            self::MachineGenerated => 'Machine generated',
        };
    }
}
