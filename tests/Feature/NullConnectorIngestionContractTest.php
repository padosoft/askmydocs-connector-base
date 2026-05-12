<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorBase\Tests\Feature;

use Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract;
use Padosoft\AskMyDocsConnectorBase\Contracts\NullConnectorIngestionContract;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorBase\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class NullConnectorIngestionContractTest extends TestCase
{
    #[Test]
    public function service_provider_binds_null_default_when_host_provides_none(): void
    {
        $resolved = $this->app->make(ConnectorIngestionContract::class);
        $this->assertInstanceOf(NullConnectorIngestionContract::class, $resolved);
    }

    #[Test]
    public function dispatch_ingestion_throws_descriptive_logic_exception(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/ConnectorIngestionContract/');

        (new NullConnectorIngestionContract)->dispatchIngestion(
            'project-key',
            'rel/path.md',
            'kb',
            'Title',
            ['k' => 'v'],
            'text/markdown',
            'tenant-1',
        );
    }

    #[Test]
    public function resolve_kb_source_path_normalises_relative_form(): void
    {
        $paths = (new NullConnectorIngestionContract)->resolveKbSourcePath('/foo\\bar/baz.md');
        $this->assertSame('foo/bar/baz.md', $paths['relative']);
        $this->assertSame('foo/bar/baz.md', $paths['absolute']);
        $this->assertSame('kb', $paths['disk']);
    }

    #[Test]
    public function redact_content_is_no_op(): void
    {
        $this->assertSame('hello', (new NullConnectorIngestionContract)->redactContent('hello'));
    }

    #[Test]
    public function emit_audit_does_not_throw(): void
    {
        (new NullConnectorIngestionContract)->emitAudit('notion', 'sync_completed', 1, ['k' => 'v']);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function soft_delete_by_remote_id_returns_false(): void
    {
        $installation = new ConnectorInstallation;
        $installation->tenant_id = 'default';
        $this->assertFalse(
            (new NullConnectorIngestionContract)->softDeleteByRemoteId($installation, 'k', 'remote-id')
        );
    }
}
