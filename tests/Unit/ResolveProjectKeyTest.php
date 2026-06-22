<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorBase\Tests\Unit;

use Padosoft\AskMyDocsConnectorBase\Auth\OAuthCredentialVault;
use Padosoft\AskMyDocsConnectorBase\Contracts\NullConnectorIngestionContract;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorBase\Tests\Support\FakeProjectKeyConnector;
use Padosoft\AskMyDocsConnectorBase\Tests\TestCase;

/**
 * {@see \Padosoft\AskMyDocsConnectorBase\BaseConnector::resolveProjectKey()}
 * is the single source of truth for the project a connector ingests
 * into: the installation's explicit `project_key`, falling back to the
 * host's `kb.ingest.default_project` config (itself defaulting to
 * `default`). This replaces the old `connector-<key>` fallback that was
 * duplicated across all eight connectors.
 */
final class ResolveProjectKeyTest extends TestCase
{
    private function connector(): FakeProjectKeyConnector
    {
        return new FakeProjectKeyConnector(
            $this->app->make(OAuthCredentialVault::class),
            $this->app->make(TenantContext::class),
            new NullConnectorIngestionContract(),
        );
    }

    public function test_returns_the_installations_project_key_when_set(): void
    {
        $installation = new ConnectorInstallation([
            'tenant_id' => 'acme',
            'connector_name' => 'fake',
            'label' => 'support',
            'project_key' => 'acme-hr',
        ]);

        $this->assertSame('acme-hr', $this->connector()->exposeResolveProjectKey($installation));
    }

    public function test_explicit_project_key_wins_over_a_configured_host_default(): void
    {
        $this->app['config']->set('kb.ingest.default_project', 'tenant-wide');

        $installation = new ConnectorInstallation([
            'tenant_id' => 'acme',
            'connector_name' => 'fake',
            'label' => 'support',
            'project_key' => 'acme-hr',
        ]);

        $this->assertSame('acme-hr', $this->connector()->exposeResolveProjectKey($installation));
    }

    public function test_falls_back_to_default_when_project_key_is_empty(): void
    {
        // No kb.ingest.default_project configured (package-only test
        // env) → the literal 'default' fallback wins.
        $this->app['config']->set('kb.ingest.default_project', null);

        $installation = new ConnectorInstallation([
            'tenant_id' => 'acme',
            'connector_name' => 'fake',
            'label' => 'support',
            'project_key' => null,
        ]);

        $this->assertSame('default', $this->connector()->exposeResolveProjectKey($installation));
    }

    public function test_honours_the_host_default_project_config_when_project_key_is_empty(): void
    {
        $this->app['config']->set('kb.ingest.default_project', 'tenant-wide');

        $installation = new ConnectorInstallation([
            'tenant_id' => 'acme',
            'connector_name' => 'fake',
            'label' => 'support',
            'project_key' => '',
        ]);

        $this->assertSame('tenant-wide', $this->connector()->exposeResolveProjectKey($installation));
    }
}
