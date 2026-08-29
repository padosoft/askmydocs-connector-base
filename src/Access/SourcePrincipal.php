<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorBase\Access;

/**
 * One entry from a source's permission list, exactly as the source stated it.
 *
 * This is a report, not a decision. The connector says "Drive told me
 * alice@example.com may read this"; it does not say who that is inside the
 * host, or whether they may read the ingested copy. Resolving an external
 * identifier to an internal subject depends on directory state the connector
 * has no access to, so it stays the host's job.
 *
 * `externalId` is kept verbatim for the same reason: normalising it here
 * would destroy the only thing the host can match on, and every source spells
 * identity differently — an email, an opaque account id, a group URN.
 */
final class SourcePrincipal
{
    public const TYPE_USER = 'user';

    public const TYPE_GROUP = 'group';

    public const TYPE_DOMAIN = 'domain';

    /** Everyone, including people outside the organisation. */
    public const TYPE_ANYONE = 'anyone';

    public const TYPES = [
        self::TYPE_USER,
        self::TYPE_GROUP,
        self::TYPE_DOMAIN,
        self::TYPE_ANYONE,
    ];

    public const EFFECT_ALLOW = 'allow';

    public const EFFECT_DENY = 'deny';

    public const EFFECTS = [
        self::EFFECT_ALLOW,
        self::EFFECT_DENY,
    ];

    public function __construct(
        public readonly string $type,
        public readonly string $externalId,
        public readonly string $effect = self::EFFECT_ALLOW,
    ) {
        if (! in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException(
                "Unknown SourcePrincipal type '{$type}'. Expected one of: ".implode(', ', self::TYPES)
            );
        }

        if (! in_array($effect, self::EFFECTS, true)) {
            throw new \InvalidArgumentException(
                "Unknown SourcePrincipal effect '{$effect}'. Expected one of: ".implode(', ', self::EFFECTS)
            );
        }

        if ($type !== self::TYPE_ANYONE && trim($externalId) === '') {
            throw new \InvalidArgumentException(
                "A '{$type}' principal needs an external id; an empty one cannot be resolved to anybody."
            );
        }
    }

    public static function user(string $externalId, string $effect = self::EFFECT_ALLOW): self
    {
        return new self(self::TYPE_USER, $externalId, $effect);
    }

    public static function group(string $externalId, string $effect = self::EFFECT_ALLOW): self
    {
        return new self(self::TYPE_GROUP, $externalId, $effect);
    }

    public static function domain(string $domain, string $effect = self::EFFECT_ALLOW): self
    {
        return new self(self::TYPE_DOMAIN, $domain, $effect);
    }

    /**
     * A public share: readable by anyone with the link, inside the
     * organisation or not.
     */
    public static function anyone(string $effect = self::EFFECT_ALLOW): self
    {
        return new self(self::TYPE_ANYONE, '*', $effect);
    }

    public function isDeny(): bool
    {
        return $this->effect === self::EFFECT_DENY;
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'external_id' => $this->externalId,
            'effect' => $this->effect,
        ];
    }
}
