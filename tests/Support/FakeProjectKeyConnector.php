<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorBase\Tests\Support;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Padosoft\AskMyDocsConnectorBase\BaseConnector;
use Padosoft\AskMyDocsConnectorBase\HealthStatus;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorBase\SyncResult;

/**
 * Minimal concrete {@see BaseConnector} used to exercise the shared
 * helpers (currently {@see BaseConnector::resolveProjectKey()}). Every
 * abstract sync/OAuth method is stubbed because the helper under test
 * never invokes them.
 */
final class FakeProjectKeyConnector extends BaseConnector
{
    public function key(): string
    {
        return 'fake';
    }

    public function displayName(): string
    {
        return 'Fake';
    }

    public function initiateOAuth(int $installationId): string
    {
        return '';
    }

    public function handleOAuthCallback(int $installationId, Request $request): void {}

    public function syncFull(int $installationId): SyncResult
    {
        throw new \LogicException('not exercised by these tests');
    }

    public function syncIncremental(int $installationId, ?Carbon $since): SyncResult
    {
        throw new \LogicException('not exercised by these tests');
    }

    public function disconnect(int $installationId): void {}

    public function health(int $installationId): HealthStatus
    {
        throw new \LogicException('not exercised by these tests');
    }

    /**
     * Public proxy so tests can drive the protected helper.
     */
    public function exposeResolveProjectKey(ConnectorInstallation $installation): string
    {
        return $this->resolveProjectKey($installation);
    }
}
