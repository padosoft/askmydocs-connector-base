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

    public function test_a_complete_empty_list_is_actionable_not_empty(): void
    {
        // "The source says nobody may read this" is a fact, and a host that
        // skipped it would leave last sync's permissions in place — the
        // document stays shared after the share was removed. Only an
        // incomplete list with nothing in it is nothing to act on.
        $this->assertFalse(SourceAccess::of([])->isEmpty());
        $this->assertTrue(SourceAccess::unknown()->isEmpty());
    }

    public function test_it_round_trips_through_metadata(): void
    {
        $original = SourceAccess::of(
            [SourcePrincipal::user('alice@example.com'), SourcePrincipal::group('eng', SourcePrincipal::EFFECT_DENY)],
            inheritsFromParent: true,
        );

        $restored = SourceAccess::fromArray($original->toArray());

        $this->assertNotNull($restored);
        $this->assertSame($original->toArray(), $restored->toArray());
    }

    public function test_absent_access_stays_absent(): void
    {
        // null means "not reported", which every connector that does not read
        // permissions produces today. It must not become an empty list.
        $this->assertNull(SourceAccess::fromArray(null));
    }

    public function test_malformed_input_decodes_to_unknown_not_to_nobody(): void
    {
        // A decoding failure must never read as "the source says nobody" —
        // that would unshare a document on the strength of a bug.
        foreach ([[], ['principals' => 'nope'], ['principals' => [['type' => 'wat']]], ['principals' => [['type' => 'user', 'external_id' => '']]]] as $raw) {
            $decoded = SourceAccess::fromArray($raw);

            $this->assertNotNull($decoded);
            $this->assertFalse($decoded->complete, 'Malformed input must decode to unknown.');
        }
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

    public function test_principals_survive_json_as_a_list_even_when_keyed(): void
    {
        // Keying by external id is the obvious way to build the array and
        // json_encode turns a map into an OBJECT, which fails the list check
        // on the way back in. The permission list would then degrade to
        // "we could not find out" purely because of how it was assembled.
        $access = SourceAccess::of([
            'alice@example.com' => SourcePrincipal::user('alice@example.com'),
            'bob@example.com' => SourcePrincipal::user('bob@example.com'),
        ]);

        $this->assertSame([0, 1], array_keys($access->principals));

        $roundTripped = SourceAccess::fromArray(
            json_decode(json_encode($access->toArray()), true),
        );

        $this->assertNotNull($roundTripped);
        $this->assertTrue($roundTripped->complete);
        $this->assertCount(2, $roundTripped->principals);
    }

    public function test_a_non_boolean_flag_decodes_to_unknown_not_to_nobody(): void
    {
        // (bool) 'false' is TRUE. A flag that survived a sloppy encode as the
        // string "false" would otherwise decode to a COMPLETE empty list --
        // "the source says nobody may read this" -- and a host acting on that
        // would revoke access nobody asked it to revoke.
        foreach (['false', 0, 1, 'true', null] as $bad) {
            $decoded = SourceAccess::fromArray([
                'principals' => [],
                'inherits_from_parent' => false,
                'complete' => $bad,
            ]);

            $this->assertNotNull($decoded);
            $this->assertFalse(
                $decoded->complete,
                sprintf('A %s complete flag must not be treated as authoritative.', get_debug_type($bad)),
            );
            $this->assertTrue($decoded->isEmpty(), 'Nothing to act on, rather than "nobody".');
        }
    }

    public function test_real_booleans_still_decode_normally(): void
    {
        $decoded = SourceAccess::fromArray([
            'principals' => [],
            'inherits_from_parent' => true,
            'complete' => true,
        ]);

        $this->assertNotNull($decoded);
        $this->assertTrue($decoded->complete);
        $this->assertTrue($decoded->inheritsFromParent);
    }
}
