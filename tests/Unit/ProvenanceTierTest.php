<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorBase\Tests\Unit;

use Padosoft\AskMyDocsConnectorBase\Contracts\DeclaresProvenance;
use Padosoft\AskMyDocsConnectorBase\ProvenanceTier;
use PHPUnit\Framework\TestCase;

final class ProvenanceTierTest extends TestCase
{
    public function test_backing_values_are_stable(): void
    {
        // These strings land in a host database column. Renaming one is a
        // migration, not a refactor, so they are pinned here on purpose.
        $this->assertSame('trusted-internal', ProvenanceTier::TrustedInternal->value);
        $this->assertSame('untrusted-external', ProvenanceTier::UntrustedExternal->value);
        $this->assertSame('machine-generated', ProvenanceTier::MachineGenerated->value);
        $this->assertCount(3, ProvenanceTier::cases());
    }

    public function test_default_preserves_the_meaning_of_connectors_that_declare_nothing(): void
    {
        // Every connector that predates DeclaresProvenance ingests content
        // authored inside the organisation. Defaulting to "untrusted" would
        // relabel an entire existing corpus overnight and make the label
        // meaningless — the point is that external content is the exception
        // a connector has to declare.
        $this->assertSame(ProvenanceTier::TrustedInternal, ProvenanceTier::default());
    }

    public function test_from_storage_reads_a_known_value(): void
    {
        $this->assertSame(ProvenanceTier::UntrustedExternal, ProvenanceTier::fromStorage('untrusted-external'));
        $this->assertSame(ProvenanceTier::TrustedInternal, ProvenanceTier::fromStorage('trusted-internal'));
    }

    public function test_absent_value_means_the_connector_declared_nothing(): void
    {
        // null is not an unknown tier, it is a known absence: no declaration,
        // which is every document written before this existed.
        $this->assertSame(ProvenanceTier::default(), ProvenanceTier::fromStorage(null));
    }

    public function test_unrecognised_value_fails_closed_instead_of_becoming_trusted(): void
    {
        // A tier written by a NEWER version, read here during a mixed
        // deployment or after a rollback. Resolving it to the trusted default
        // would invert the protection: a future tier meant to be MORE
        // restrictive would read as safe, and isExternallyAuthored() would
        // answer false for content nobody vouched for.
        foreach (['tier-from-the-future', '', 'TRUSTED-INTERNAL', 'null'] as $unknown) {
            $tier = ProvenanceTier::fromStorage($unknown);

            $this->assertSame(ProvenanceTier::UntrustedExternal, $tier, "'{$unknown}' must not read as trusted.");
            $this->assertTrue($tier->isExternallyAuthored());
        }

        $this->assertNotSame(
            ProvenanceTier::fromStorage('tier-from-the-future'),
            ProvenanceTier::fromStorage(null),
            'Absent and unrecognised are different facts and must not collapse.',
        );
    }

    public function test_external_authorship_is_named_once(): void
    {
        $this->assertTrue(ProvenanceTier::UntrustedExternal->isExternallyAuthored());
        $this->assertFalse(ProvenanceTier::TrustedInternal->isExternallyAuthored());

        // Machine-generated is not external authorship: it was produced by
        // the organisation's own pipeline. It gets its own enforcement story.
        $this->assertFalse(ProvenanceTier::MachineGenerated->isExternallyAuthored());
    }

    public function test_every_case_has_a_label(): void
    {
        foreach (ProvenanceTier::cases() as $tier) {
            $this->assertNotSame('', $tier->label());
        }
    }

    public function test_the_capability_interface_is_opt_in(): void
    {
        // A connector that does not implement the interface must remain
        // valid: eight connectors and a public template consume this
        // contract, so declaring provenance can never become mandatory.
        $declaring = new class implements DeclaresProvenance
        {
            public function provenanceTier(int $installationId): ProvenanceTier
            {
                return ProvenanceTier::UntrustedExternal;
            }
        };

        $silent = new class {};

        $this->assertInstanceOf(DeclaresProvenance::class, $declaring);
        $this->assertNotInstanceOf(DeclaresProvenance::class, $silent);
        $this->assertSame(ProvenanceTier::UntrustedExternal, $declaring->provenanceTier(1));
    }

    public function test_the_tier_is_resolved_per_installation(): void
    {
        // One connector class, two installations with opposite authorship
        // models -- an internal distribution list and a public contact
        // address are the same IMAP code. ConnectorRegistry keeps a single
        // instance per connector key, so the installation id has to be an
        // argument; without it an implementation would need ambient state
        // that is not set when the host resolves the tier during ingestion.
        $connector = new class implements DeclaresProvenance
        {
            /** @var array<int, ProvenanceTier> */
            public array $perInstallation = [];

            public function provenanceTier(int $installationId): ProvenanceTier
            {
                return $this->perInstallation[$installationId] ?? ProvenanceTier::default();
            }
        };

        $connector->perInstallation = [
            7 => ProvenanceTier::TrustedInternal,
            9 => ProvenanceTier::UntrustedExternal,
        ];

        $this->assertSame(ProvenanceTier::TrustedInternal, $connector->provenanceTier(7));
        $this->assertSame(ProvenanceTier::UntrustedExternal, $connector->provenanceTier(9));
        $this->assertSame(ProvenanceTier::default(), $connector->provenanceTier(404));
    }
}
