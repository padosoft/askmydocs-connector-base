<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorBase\Tests\Unit;

use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use PHPUnit\Framework\TestCase;

final class TenantContextTest extends TestCase
{
    public function test_default_is_default(): void
    {
        $ctx = new TenantContext;

        $this->assertSame('default', $ctx->current());
        $this->assertTrue($ctx->isDefault());
    }

    public function test_set_and_reset(): void
    {
        $ctx = new TenantContext;
        $ctx->set('acme');

        $this->assertSame('acme', $ctx->current());
        $this->assertFalse($ctx->isDefault());

        $ctx->reset();
        $this->assertSame('default', $ctx->current());
    }

    public function test_empty_string_falls_back_to_default(): void
    {
        $ctx = new TenantContext;
        $ctx->set('');

        $this->assertSame('default', $ctx->current());
    }

    public function test_invalid_format_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new TenantContext)->set('Invalid Tenant!');
    }

    public function test_max_length_enforced(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new TenantContext)->set(str_repeat('a', 51));
    }
}
