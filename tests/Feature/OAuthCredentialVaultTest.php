<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorBase\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Padosoft\AskMyDocsConnectorBase\Auth\OAuthCredentialVault;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorAuthException;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorCredential;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorBase\Tests\TestCase;

/**
 * OAuthCredentialVault tests: encryption-at-rest, tenant scoping
 * (R30), credential lifecycle, R21 atomic setExtraKey.
 */
final class OAuthCredentialVaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_set_credentials_persists_encrypted_tokens(): void
    {
        $installation = $this->makeInstallation();

        $this->vault()->setCredentials(
            $installation->id,
            accessToken: 'plain-access-token',
            refreshToken: 'plain-refresh-token',
            expiresAt: Carbon::now()->addHour(),
            extra: ['scope' => 'drive.readonly'],
        );

        $row = ConnectorCredential::query()
            ->where('connector_installation_id', $installation->id)
            ->first();

        $this->assertNotNull($row);

        // The DB row never holds plaintext.
        $this->assertNotSame('plain-access-token', $row->encrypted_access_token);
        $this->assertNotSame('plain-refresh-token', $row->encrypted_refresh_token);

        // The Crypt round-trip recovers the originals.
        $this->assertSame('plain-access-token', Crypt::decryptString($row->encrypted_access_token));
        $this->assertSame('plain-refresh-token', Crypt::decryptString($row->encrypted_refresh_token));
        $this->assertSame(['scope' => 'drive.readonly'], $row->extra_json);
    }

    public function test_get_access_token_returns_decrypted_value(): void
    {
        $installation = $this->makeInstallation(status: ConnectorInstallation::STATUS_ACTIVE);

        $this->vault()->setCredentials(
            $installation->id,
            accessToken: 'plain-access-token',
            expiresAt: Carbon::now()->addHour(),
        );

        $this->assertSame('plain-access-token', $this->vault()->getAccessToken($installation->id));
    }

    public function test_expired_access_token_returns_null(): void
    {
        $installation = $this->makeInstallation(status: ConnectorInstallation::STATUS_ACTIVE);

        $this->vault()->setCredentials(
            $installation->id,
            accessToken: 'plain-access-token',
            refreshToken: 'plain-refresh-token',
            // Expired 1 second ago.
            expiresAt: Carbon::now()->subSecond(),
        );

        $this->assertNull($this->vault()->getAccessToken($installation->id));

        // Refresh token is still recoverable so the connector can mint
        // a new access token.
        $this->assertSame('plain-refresh-token', $this->vault()->getRefreshToken($installation->id));
    }

    public function test_tenant_isolation_blocks_cross_tenant_access(): void
    {
        $installationA = $this->makeInstallation(tenantId: 'tenant-a', status: ConnectorInstallation::STATUS_ACTIVE);

        // Persist credentials under tenant-a's active context.
        $this->tenantContext()->set('tenant-a');
        $this->vault()->setCredentials(
            $installationA->id,
            accessToken: 'tenant-a-secret',
            expiresAt: Carbon::now()->addHour(),
        );

        // Now switch to tenant-b — vault must refuse to return
        // tenant-a's installation's credentials.
        $this->tenantContext()->set('tenant-b');
        $this->assertNull($this->vault()->getAccessToken($installationA->id));
        $this->assertNull($this->vault()->getRefreshToken($installationA->id));
        $this->assertNull($this->vault()->getCredentialRow($installationA->id));
        $this->assertSame([], $this->vault()->getExtra($installationA->id));

        // clearCredentials on a cross-tenant id is a no-op (returns 0).
        $this->assertSame(0, $this->vault()->clearCredentials($installationA->id));
        $this->assertDatabaseHas('connector_credentials', [
            'connector_installation_id' => $installationA->id,
        ]);

        $this->tenantContext()->reset();
    }

    public function test_clear_credentials_removes_row(): void
    {
        $installation = $this->makeInstallation(status: ConnectorInstallation::STATUS_ACTIVE);

        $this->vault()->setCredentials(
            $installation->id,
            accessToken: 'plain-access-token',
            expiresAt: Carbon::now()->addHour(),
        );

        $this->assertSame(1, $this->vault()->clearCredentials($installation->id));
        $this->assertDatabaseMissing('connector_credentials', [
            'connector_installation_id' => $installation->id,
        ]);

        // Idempotent — second clear returns 0.
        $this->assertSame(0, $this->vault()->clearCredentials($installation->id));
    }

    public function test_set_credentials_overwrites_existing_row(): void
    {
        $installation = $this->makeInstallation(status: ConnectorInstallation::STATUS_ACTIVE);

        $this->vault()->setCredentials($installation->id, 'token-1');
        $this->vault()->setCredentials($installation->id, 'token-2');

        $this->assertSame(
            1,
            ConnectorCredential::query()
                ->where('connector_installation_id', $installation->id)
                ->count(),
        );
        $this->assertSame('token-2', $this->vault()->getAccessToken($installation->id));
    }

    public function test_set_credentials_throws_when_installation_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->vault()->setCredentials(999_999, 'token');
    }

    public function test_set_extra_stores_value_in_extra_json(): void
    {
        $installation = $this->makeInstallationWithCredential();

        $this->vault()->setExtraKey($installation->id, 'bot_id', 'bot-123');

        $row = ConnectorCredential::query()
            ->where('connector_installation_id', $installation->id)
            ->first();

        $this->assertSame('bot-123', $row->extra_json['bot_id']);
    }

    public function test_get_extra_returns_null_for_missing_key(): void
    {
        $installation = $this->makeInstallationWithCredential([
            'workspace_id' => 'ws-1',
        ]);

        $this->assertNull(
            $this->vault()->getExtraKey($installation->id, 'never_set')
        );
    }

    public function test_set_extra_overrides_existing_value(): void
    {
        $installation = $this->makeInstallationWithCredential([
            'bot_id' => 'bot-old',
        ]);

        $this->vault()->setExtraKey($installation->id, 'bot_id', 'bot-new');

        $this->assertSame(
            'bot-new',
            $this->vault()->getExtraKey($installation->id, 'bot_id')
        );
    }

    public function test_set_extra_preserves_other_keys(): void
    {
        $installation = $this->makeInstallationWithCredential([
            'bot_id' => 'bot-123',
            'workspace_id' => 'ws-456',
            'workspace_name' => 'Acme Workspace',
        ]);

        $this->vault()->setExtraKey($installation->id, 'changes_page_token', 'cursor-1');

        $extra = $this->vault()->getExtra($installation->id);
        $this->assertSame('bot-123', $extra['bot_id']);
        $this->assertSame('ws-456', $extra['workspace_id']);
        $this->assertSame('Acme Workspace', $extra['workspace_name']);
        $this->assertSame('cursor-1', $extra['changes_page_token']);
    }

    public function test_set_extra_key_throws_if_credentials_deleted_concurrently(): void
    {
        // R21 — no credential row exists (simulating a parallel
        // `disconnect()` that ran between the caller's outer
        // `getCredentialRow()` check and the `setExtraKey()` call).
        // The vault MUST refuse to recreate the row — silently
        // recreating would make a fresh credential row with no
        // access token (impossible to authenticate) and would mask
        // the disconnect from the operator.
        $installation = $this->makeInstallation(connectorName: 'notion');

        $this->expectException(ConnectorAuthException::class);
        $this->expectExceptionMessage('credential row was deleted concurrently');

        $this->vault()->setExtraKey($installation->id, 'bot_id', 'bot-x');
    }

    public function test_set_extra_key_concurrent_updates_dont_lose_data(): void
    {
        // R21 — the implementation holds `lockForUpdate()` inside a
        // `DB::transaction`, so the read and write are atomic
        // relative to other writers. Two sequential writes (the
        // closest we can simulate single-process) MUST land both
        // values; the OLD read-modify-write `updateOrCreate` lost
        // siblings under contention because each thread re-read +
        // re-wrote without holding a lock.
        $installation = $this->makeInstallationWithCredential([
            'workspace_id' => 'ws-1',
        ]);

        $this->vault()->setExtraKey($installation->id, 'bot_id', 'bot-A');
        $this->vault()->setExtraKey($installation->id, 'changes_page_token', 'cursor-B');

        $extra = $this->vault()->getExtra($installation->id);
        $this->assertSame('ws-1', $extra['workspace_id'], 'pre-existing key preserved');
        $this->assertSame('bot-A', $extra['bot_id'], 'first write preserved');
        $this->assertSame('cursor-B', $extra['changes_page_token'], 'second write preserved');
    }

    public function test_set_extra_does_not_corrupt_encrypted_tokens(): void
    {
        $installation = $this->makeInstallationWithCredential();

        $this->vault()->setExtraKey($installation->id, 'workspace_id', 'ws-1');

        // The access token must still decrypt to its original value.
        $this->assertSame('AT-xyz', $this->vault()->getAccessToken($installation->id));
    }

    private function vault(): OAuthCredentialVault
    {
        return $this->app->make(OAuthCredentialVault::class);
    }

    private function tenantContext(): TenantContext
    {
        return $this->app->make(TenantContext::class);
    }

    private function makeInstallation(string $tenantId = 'default', string $connectorName = 'google-drive', string $status = ConnectorInstallation::STATUS_PENDING): ConnectorInstallation
    {
        return ConnectorInstallation::create([
            'tenant_id' => $tenantId,
            'connector_name' => $connectorName,
            'status' => $status,
            'created_by' => null,
        ]);
    }

    /**
     * @param  array<string,mixed>  $initialExtra
     */
    private function makeInstallationWithCredential(array $initialExtra = []): ConnectorInstallation
    {
        $installation = $this->makeInstallation(connectorName: 'notion', status: ConnectorInstallation::STATUS_ACTIVE);

        ConnectorCredential::create([
            'tenant_id' => 'default',
            'connector_installation_id' => $installation->id,
            'encrypted_access_token' => Crypt::encryptString('AT-xyz'),
            'encrypted_refresh_token' => null,
            'expires_at' => Carbon::now()->addYears(10),
            'extra_json' => $initialExtra === [] ? null : $initialExtra,
        ]);

        return $installation;
    }
}
