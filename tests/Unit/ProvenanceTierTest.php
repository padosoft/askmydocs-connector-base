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

    public function test_from_storage_falls_back_rather_than_throwing(): void
    {
        $this->assertSame(ProvenanceTier::UntrustedExternal, ProvenanceTier::fromStorage('untrusted-external'));
        $this->assertSame(ProvenanceTier::default(), ProvenanceTier::fromStorage(null));

        // A value written by a newer version of this package, or by hand,
        // must not crash a reader on an older one.
        $this->assertSame(ProvenanceTier::default(), ProvenanceTier::fromStorage('tier-from-the-future'));
        $this->assertSame(ProvenanceTier::default(), ProvenanceTier::fromStorage(''));
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
            public function provenanceTier(): ProvenanceTier
            {
                return ProvenanceTier::UntrustedExternal;
            }
        };

        $silent = new class {};

        $this->assertInstanceOf(DeclaresProvenance::class, $declaring);
        $this->assertNotInstanceOf(DeclaresProvenance::class, $silent);
        $this->assertSame(ProvenanceTier::UntrustedExternal, $declaring->provenanceTier());
    }
}
