<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorBase\Tests\Unit;

use Padosoft\AskMyDocsConnectorBase\Support\CredentialField;
use PHPUnit\Framework\TestCase;

final class CredentialFieldTest extends TestCase
{
    public function test_select_field_round_trips_through_to_array(): void
    {
        $field = new CredentialField(
            name: 'auth_mode',
            label: 'Authentication Mode',
            type: 'select',
            target: 'auth_mode',
            required: true,
            secret: false,
            default: 'basic',
            options: ['basic' => 'Basic (user/password)', 'oauth2' => 'OAuth 2.0'],
            showIf: ['field' => 'provider', 'equals' => 'imap'],
            help: 'Choose the authentication method.',
            group: 'Connection',
        );

        $array = $field->toArray();

        $this->assertSame('auth_mode', $array['name']);
        $this->assertSame('Authentication Mode', $array['label']);
        $this->assertSame('select', $array['type']);
        $this->assertSame('auth_mode', $array['target']);
        $this->assertTrue($array['required']);
        $this->assertFalse($array['secret']);
        $this->assertSame('basic', $array['default']);
        $this->assertSame(['basic' => 'Basic (user/password)', 'oauth2' => 'OAuth 2.0'], $array['options']);
        $this->assertSame(['field' => 'provider', 'equals' => 'imap'], $array['showIf']);
        $this->assertSame('Choose the authentication method.', $array['help']);
        $this->assertSame('Connection', $array['group']);
        $this->assertNull($array['discovery']);
        $this->assertCount(12, $array);
    }

    public function test_minimal_text_field_has_sensible_defaults(): void
    {
        $field = new CredentialField(
            name: 'host',
            label: 'IMAP Host',
            type: 'text',
            target: 'connection',
        );

        $array = $field->toArray();

        $this->assertSame('host', $array['name']);
        $this->assertSame('IMAP Host', $array['label']);
        $this->assertSame('text', $array['type']);
        $this->assertSame('connection', $array['target']);
        $this->assertFalse($array['required']);
        $this->assertFalse($array['secret']);
        $this->assertNull($array['default']);
        $this->assertSame([], $array['options']);
        $this->assertNull($array['showIf']);
        $this->assertNull($array['help']);
        $this->assertNull($array['group']);
        $this->assertNull($array['discovery']);
    }

    public function test_secret_password_field_is_flagged(): void
    {
        $field = new CredentialField(
            name: 'password',
            label: 'Password',
            type: 'password',
            target: 'secret',
            required: true,
            secret: true,
        );

        $array = $field->toArray();

        $this->assertTrue($array['secret']);
        $this->assertSame('secret', $array['target']);
        $this->assertTrue($array['required']);
    }

    public function test_to_array_always_returns_all_twelve_keys(): void
    {
        $field = new CredentialField(name: 'x', label: 'X', type: 'text', target: 'config');

        $keys = array_keys($field->toArray());

        $this->assertSame(
            ['name', 'label', 'type', 'target', 'required', 'secret', 'default', 'options', 'showIf', 'help', 'group', 'discovery'],
            $keys,
        );
    }

    public function test_multiselect_field_with_live_discovery_round_trips(): void
    {
        $field = new CredentialField(
            name: 'folders.include',
            label: 'Folders to sync',
            type: 'multiselect',
            target: 'config',
            default: [],
            help: 'Empty = sync every non-excluded folder.',
            group: 'Folders',
            discovery: 'folders',
        );

        $array = $field->toArray();

        $this->assertSame('folders.include', $array['name']);
        $this->assertSame('multiselect', $array['type']);
        $this->assertSame('config', $array['target']);
        $this->assertSame([], $array['default']);
        $this->assertSame([], $array['options']);
        $this->assertSame('folders', $array['discovery']);
        $this->assertSame('Folders', $array['group']);
    }

    public function test_tags_field_is_an_open_free_text_list(): void
    {
        $field = new CredentialField(
            name: 'senders.exclude',
            label: 'Exclude senders',
            type: 'tags',
            target: 'config',
            default: [],
            group: 'Filtering',
        );

        $array = $field->toArray();

        $this->assertSame('tags', $array['type']);
        $this->assertSame([], $array['options']);
        $this->assertNull($array['discovery']);
        $this->assertSame([], $array['default']);
    }

    public function test_multiselect_field_with_a_fixed_option_set_has_no_discovery(): void
    {
        $field = new CredentialField(
            name: 'body_formats',
            label: 'Body formats',
            type: 'multiselect',
            target: 'config',
            default: ['prefer_text'],
            options: ['prefer_text' => 'Prefer text', 'prefer_html' => 'Prefer HTML'],
            group: 'Content',
        );

        $array = $field->toArray();

        $this->assertSame('multiselect', $array['type']);
        $this->assertSame(['prefer_text' => 'Prefer text', 'prefer_html' => 'Prefer HTML'], $array['options']);
        $this->assertNull($array['discovery']);
        $this->assertSame(['prefer_text'], $array['default']);
    }
}
