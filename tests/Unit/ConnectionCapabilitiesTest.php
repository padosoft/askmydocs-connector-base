<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorBase\Tests\Unit;

use Padosoft\AskMyDocsConnectorBase\Contracts\SupportsConnectionSettings;
use Padosoft\AskMyDocsConnectorBase\Contracts\SupportsFolderDiscovery;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorApiException;
use Padosoft\AskMyDocsConnectorBase\Support\CredentialField;
use PHPUnit\Framework\TestCase;

/**
 * Documents the v1.4.0 capability contracts: a connector opts into folder
 * discovery and/or an editable settings schema, and the host detects each via
 * instanceof (R23 — no connector-name branch).
 */
final class ConnectionCapabilitiesTest extends TestCase
{
    public function test_a_connector_can_opt_into_both_capabilities(): void
    {
        $connector = $this->fakeConnector();

        $this->assertInstanceOf(SupportsFolderDiscovery::class, $connector);
        $this->assertInstanceOf(SupportsConnectionSettings::class, $connector);
    }

    public function test_folder_discovery_returns_the_live_container_list(): void
    {
        $folders = $this->fakeConnector()->listAvailableFolders(42);

        $this->assertSame(['INBOX', 'Archive', '[Gmail]/Sent Mail'], $folders);
    }

    public function test_folder_discovery_surfaces_failure_as_connector_api_exception(): void
    {
        $connector = $this->fakeConnector(fail: true);

        $this->expectException(ConnectorApiException::class);
        $connector->listAvailableFolders(7);
    }

    public function test_folder_discovery_allows_an_empty_container_list(): void
    {
        // An empty list is a valid result (the source genuinely has no
        // containers) and must be distinguishable from a failure — it does NOT throw.
        $folders = $this->fakeConnector(folders: [])->listAvailableFolders(99);

        $this->assertSame([], $folders);
    }

    public function test_settings_schema_is_a_list_of_serialisable_config_fields(): void
    {
        $schema = $this->fakeConnector()->connectionSettingsSchema();

        $this->assertNotEmpty($schema);
        foreach ($schema as $field) {
            $this->assertIsArray($field);
            // Same 12-key shape the host's field renderer consumes.
            $this->assertSame(
                ['name', 'label', 'type', 'target', 'required', 'secret', 'default', 'options', 'showIf', 'help', 'group', 'discovery'],
                array_keys($field),
            );
            // Settings are plain config, never secrets (contract invariant).
            $this->assertSame('config', $field['target']);
            $this->assertFalse($field['secret']);
        }

        // A live 'multiselect' must name its discovery source.
        $include = $this->fieldByName($schema, 'folders.include');
        $this->assertSame('multiselect', $include['type']);
        $this->assertSame('folders', $include['discovery']);
    }

    /**
     * @param  list<array<string,mixed>>  $schema
     * @return array<string,mixed>
     */
    private function fieldByName(array $schema, string $name): array
    {
        foreach ($schema as $field) {
            if ($field['name'] === $name) {
                return $field;
            }
        }

        $this->fail("Field '{$name}' not present in schema.");
    }

    /**
     * @param  list<string>  $folders
     */
    private function fakeConnector(bool $fail = false, array $folders = ['INBOX', 'Archive', '[Gmail]/Sent Mail']): SupportsFolderDiscovery&SupportsConnectionSettings
    {
        return new class($fail, $folders) implements SupportsConnectionSettings, SupportsFolderDiscovery
        {
            /** @param  list<string>  $folders */
            public function __construct(private bool $fail, private array $folders) {}

            public function listAvailableFolders(int $installationId): array
            {
                if ($this->fail) {
                    throw new ConnectorApiException('source unreachable');
                }

                return $this->folders;
            }

            public function connectionSettingsSchema(): array
            {
                return [
                    (new CredentialField(
                        name: 'folders.include',
                        label: 'Folders to sync',
                        type: 'multiselect',
                        target: 'config',
                        default: [],
                        group: 'Folders',
                        discovery: 'folders',
                    ))->toArray(),
                    (new CredentialField(
                        name: 'date_window_days',
                        label: 'Sync window (days)',
                        type: 'number',
                        target: 'config',
                        default: 365,
                        group: 'Sync window',
                    ))->toArray(),
                ];
            }
        };
    }
}
