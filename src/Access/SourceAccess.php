<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorBase\Access;

/**
 * What a source said about who may read one item.
 *
 * The connector reports; the host decides. Nothing here is an authorization
 * outcome — it is the upstream permission list, carried across the ingestion
 * boundary so the host can resolve it against its own directory instead of
 * discarding it, which is what ingestion did before.
 *
 * The two flags exist because a permission list can be incomplete in two very
 * different ways, and collapsing them would lose the distinction that matters:
 *
 *   - {@see $inheritsFromParent} — the item adds nothing of its own and takes
 *     its parent's permissions. An empty `principals` here means "as the
 *     folder says", not "nobody".
 *   - {@see $complete} — the source truncated the list, rate-limited, or
 *     refused part of it. An empty `principals` here means "we do not know",
 *     which must never read as "nobody" nor as "everybody".
 *
 * A host that treats an incomplete list as authoritative would grant or deny
 * on evidence it does not have. That is why `complete` defaults to true only
 * when a connector says so explicitly: {@see unknown()} exists for the case
 * where it cannot.
 */
final class SourceAccess
{
    /**
     * The reserved key this DTO travels under inside ingestion metadata.
     *
     * It does NOT go on `dispatchIngestion()` as a parameter. Adding one to
     * the interface — even an optional trailing one — is a breaking change
     * for every HOST that implements the contract: PHP rejects an
     * implementation with fewer parameters than the interface declares, so
     * an application binding its own implementation would fatal on upgrade
     * before a single line of its code ran. Connectors are callers and would
     * have been fine; hosts are implementers and would not.
     *
     * `$metadata` is already the extension channel for exactly this kind of
     * per-document fact, and using it keeps the contract additive in the way
     * the rest of the framework is.
     */
    public const METADATA_KEY = '_source_access';

    /**
     * Always a list, never a map — see the constructor.
     *
     * @var list<SourcePrincipal>
     */
    public readonly array $principals;

    /**
     * Deliberately typed loose: the guard below is the real contract, and a
     * docblock promising SourcePrincipal elements would make it look
     * redundant while callers -- ordinary untyped PHP in eleven connectors
     * -- can still pass anything at runtime.
     *
     * @param  array<array-key, mixed>  $principals
     */
    public function __construct(
        array $principals = [],
        public readonly bool $inheritsFromParent = false,
        public readonly bool $complete = true,
    ) {
        foreach ($principals as $principal) {
            if (! $principal instanceof SourcePrincipal) {
                throw new \InvalidArgumentException(
                    'SourceAccess::$principals must contain only SourcePrincipal instances.'
                );
            }
        }

        // Reindexed on the way in, because keying by external id is the
        // obvious thing for a connector to do and json_encode turns a map
        // into an OBJECT. That object then fails the list check in
        // fromArray() and decodes to unknown() — the permission list would
        // silently degrade to "we could not find out" for no reason other
        // than how the caller happened to build the array.
        $this->principals = array_values($principals);
    }

    /**
     * The source was asked and answered in full.
     *
     * @param  list<SourcePrincipal>  $principals
     */
    public static function of(array $principals, bool $inheritsFromParent = false): self
    {
        return new self($principals, $inheritsFromParent, true);
    }

    /**
     * The source could not be asked, or answered partially.
     *
     * Distinct from an empty allow-list on purpose: a host must be able to
     * tell "nobody may read this" from "we could not find out", because the
     * safe response to the second is not the same as the first.
     *
     * @param  list<SourcePrincipal>  $partial  Whatever was retrieved, if anything.
     */
    public static function unknown(array $partial = []): self
    {
        return new self($partial, false, false);
    }

    /**
     * The item carries no permissions of its own and follows its container.
     */
    public static function inherited(): self
    {
        return new self([], true, true);
    }

    /**
     * Whether the source stated that anyone can read this, including people
     * outside the organisation.
     */
    public function isPublic(): bool
    {
        foreach ($this->principals as $principal) {
            if ($principal->type === SourcePrincipal::TYPE_ANYONE && ! $principal->isDeny()) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when the host has nothing to act on.
     *
     * A COMPLETE empty allow-list is not empty in this sense: "the source
     * says nobody may read this" is a fact, and a host skipping it would
     * leave whatever permissions it mirrored last time in place — the
     * document stays shared after the share was removed. That is the slow
     * leak reconciliation exists to prevent, so only an incomplete list with
     * nothing in it and nothing to inherit counts as empty.
     */
    public function isEmpty(): bool
    {
        return $this->principals === []
            && ! $this->inheritsFromParent
            && ! $this->complete;
    }

    /**
     * Rebuild from the array form, for the trip through ingestion metadata.
     *
     * Unknown or malformed input yields {@see unknown()} rather than an empty
     * allow-list: a host must never read a decoding failure as "the source
     * says nobody", which would unshare a document on the strength of a bug.
     *
     * @param  array<string, mixed>|null  $raw
     */
    public static function fromArray(?array $raw): ?self
    {
        if ($raw === null) {
            return null;
        }

        if (! is_array($raw['principals'] ?? null)) {
            return self::unknown();
        }

        $principals = [];

        foreach ($raw['principals'] as $entry) {
            if (! is_array($entry) || ! is_string($entry['type'] ?? null)) {
                return self::unknown();
            }

            try {
                $principals[] = new SourcePrincipal(
                    $entry['type'],
                    (string) ($entry['external_id'] ?? ''),
                    (string) ($entry['effect'] ?? SourcePrincipal::EFFECT_ALLOW),
                );
            } catch (\InvalidArgumentException) {
                return self::unknown();
            }
        }

        $inherits = $raw['inherits_from_parent'] ?? false;
        $complete = $raw['complete'] ?? false;

        // Strict on purpose: `(bool) 'false'` is TRUE, so a flag that
        // survived a sloppy encode as the STRING "false" would decode to a
        // COMPLETE empty list — "the source says nobody" — and a host acting
        // on that would revoke access nobody asked it to revoke. The two
        // flags decide whether the list is authoritative, so a value whose
        // type we do not recognise is not a flag, it is a decode failure.
        if (! is_bool($inherits) || ! is_bool($complete)) {
            return self::unknown();
        }

        return new self($principals, $inherits, $complete);
    }

    /**
     * @return array{principals: list<array<string, string>>, inherits_from_parent: bool, complete: bool}
     */
    public function toArray(): array
    {
        return [
            'principals' => array_map(
                static fn (SourcePrincipal $p): array => $p->toArray(),
                $this->principals,
            ),
            'inherits_from_parent' => $this->inheritsFromParent,
            'complete' => $this->complete,
        ];
    }
}
