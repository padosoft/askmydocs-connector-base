<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorBase\Tests\Unit;

use Padosoft\AskMyDocsConnectorBase\Support\Metadata\RecencyBucketer;
use Padosoft\AskMyDocsConnectorBase\Support\Metadata\SourceAwareMetadataBuilder;
use Padosoft\AskMyDocsConnectorBase\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class SourceAwareMetadataBuilderTest extends TestCase
{
    #[Test]
    public function build_emits_converter_hints_with_source_namespace_and_derived_block(): void
    {
        $builder = new SourceAwareMetadataBuilder;
        $meta = $builder->build(
            base: ['connector' => 'notion', 'installation_id' => 7],
            sourceKey: 'notion',
            sourceFields: ['database_id' => 'db-1', 'properties' => ['title' => 'Doc']],
            tags: ['alpha', 'beta', 'alpha'],
            statusActive: true,
            lastModified: null,
            owner: 'alice@example.com',
        );

        $this->assertSame('notion', $meta['connector']);
        $this->assertSame(7, $meta['installation_id']);
        $this->assertArrayHasKey('converter_hints', $meta);
        $this->assertSame('db-1', $meta['converter_hints']['notion']['database_id']);
        $this->assertSame(['alpha', 'beta'], $meta['converter_hints']['_derived']['search_tags']);
        $this->assertTrue($meta['converter_hints']['_derived']['status_active']);
        $this->assertSame('alice@example.com', $meta['converter_hints']['_derived']['owner']);
        $this->assertSame(RecencyBucketer::BUCKET_OLDER, $meta['converter_hints']['_derived']['recency_bucket']);
        // Flat _derived projection for legacy readers.
        $this->assertSame($meta['converter_hints']['_derived'], $meta['_derived']);
    }

    #[Test]
    public function status_active_null_degrades_to_false(): void
    {
        $builder = new SourceAwareMetadataBuilder;
        $meta = $builder->build(
            base: [],
            sourceKey: 'google_drive',
            sourceFields: [],
            statusActive: null,
        );

        $this->assertFalse($meta['_derived']['status_active']);
    }

    #[Test]
    public function tags_are_trimmed_and_deduplicated(): void
    {
        $builder = new SourceAwareMetadataBuilder;
        $meta = $builder->build(
            base: [],
            sourceKey: 'notion',
            sourceFields: [],
            tags: [' alpha', 'alpha ', 'beta', '', '  '],
        );

        $this->assertSame(['alpha', 'beta'], $meta['_derived']['search_tags']);
    }
}
