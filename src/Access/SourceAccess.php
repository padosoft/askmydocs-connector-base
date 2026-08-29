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
     * @param  list<SourcePrincipal>  $principals
     */
    public function __construct(
        public readonly array $principals = [],
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
     * True when the host has nothing actionable: no principals, no
     * inheritance to follow, and no claim that the list is complete.
     */
    public function isEmpty(): bool
    {
        return $this->principals === [] && ! $this->inheritsFromParent;
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
