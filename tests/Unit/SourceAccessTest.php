<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorBase\Tests\Unit;

use InvalidArgumentException;
use Padosoft\AskMyDocsConnectorBase\Access\SourceAccess;
use Padosoft\AskMyDocsConnectorBase\Access\SourcePrincipal;
use Padosoft\AskMyDocsConnectorBase\Contracts\SupportsSourceAcl;
use PHPUnit\Framework\TestCase;

final class SourceAccessTest extends TestCase
{
    public function test_a_principal_keeps_the_external_id_verbatim(): void
    {
        // Normalising here would destroy the only thing the host can match
        // on, and every source spells identity differently.
        $p = SourcePrincipal::user('  Alice@Example.COM ');

        $this->assertSame('  Alice@Example.COM ', $p->externalId);
        $this->assertSame(SourcePrincipal::TYPE_USER, $p->type);
        $this->assertSame(SourcePrincipal::EFFECT_ALLOW, $p->effect);
    }

    public function test_an_unresolvable_principal_is_rejected_at_construction(): void
    {
        // A user or group with no identifier cannot be resolved to anybody.
        // Accepting it would push a guaranteed-unmappable row into the host,
        // where it would land in the triage queue as noise.
        $this->expectException(InvalidArgumentException::class);
        SourcePrincipal::user('   ');
    }

    public function test_anyone_needs_no_identifier(): void
    {
        // "Anyone" is the one type that is complete without one.
        $p = SourcePrincipal::anyone();

        $this->assertSame(SourcePrincipal::TYPE_ANYONE, $p->type);
        $this->assertTrue((new SourceAccess([$p]))->isPublic());
    }

    public function test_a_deny_for_anyone_is_not_a_public_share(): void
    {
        $access = new SourceAccess([SourcePrincipal::anyone(SourcePrincipal::EFFECT_DENY)]);

        $this->assertFalse($access->isPublic());
    }

    public function test_unknown_is_not_the_same_fact_as_an_empty_allow_list(): void
    {
        // The distinction the whole design rests on: "nobody may read this"
        // and "we could not find out" must not collapse, because the safe
        // response to the second is not the same as to the first.
        $nobody = SourceAccess::of([]);
        $dontKnow = SourceAccess::unknown();

        $this->assertTrue($nobody->complete);
        $this->assertFalse($dontKnow->complete);
        $this->assertSame($nobody->principals, $dontKnow->principals);
        $this->assertNotSame($nobody->complete, $dontKnow->complete);
    }

    public function test_unknown_can_still_carry_what_was_retrieved(): void
    {
        // A truncated list is partial evidence, not no evidence — the host
        // should see both the rows and the fact that more may exist.
        $access = SourceAccess::unknown([SourcePrincipal::user('alice@example.com')]);

        $this->assertCount(1, $access->principals);
        $this->assertFalse($access->complete);
    }

    public function test_inherited_means_as_the_parent_says_not_nobody(): void
    {
        $access = SourceAccess::inherited();

        $this->assertSame([], $access->principals);
        $this->assertTrue($access->inheritsFromParent);
        $this->assertTrue($access->complete);
        $this->assertFalse($access->isEmpty(), 'Inheritance is something to follow, not an absence.');
    }

    public function test_only_principals_are_accepted(): void
    {
        $this->expectException(InvalidArgumentException::class);
        /** @phpstan-ignore-next-line intentionally wrong type */
        new SourceAccess(['alice@example.com']);
    }

    public function test_the_capability_is_opt_in(): void
    {
        // Eleven connectors and a public template consume the ingestion
        // contract; reading ACLs can never become mandatory.
        $reads = new class implements SupportsSourceAcl
        {
            public function readAcl(string $remoteId): SourceAccess
            {
                return SourceAccess::of([SourcePrincipal::user('alice@example.com')]);
            }
        };

        $silent = new class {};

        $this->assertInstanceOf(SupportsSourceAcl::class, $reads);
        $this->assertNotInstanceOf(SupportsSourceAcl::class, $silent);
        $this->assertCount(1, $reads->readAcl('remote-1')->principals);
    }
}
