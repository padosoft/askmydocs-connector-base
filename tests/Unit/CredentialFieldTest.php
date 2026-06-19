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
        $this->assertCount(11, $array);
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

    public function test_to_array_always_returns_all_eleven_keys(): void
    {
        $field = new CredentialField(name: 'x', label: 'X', type: 'text', target: 'config');

        $keys = array_keys($field->toArray());

        $this->assertSame(
            ['name', 'label', 'type', 'target', 'required', 'secret', 'default', 'options', 'showIf', 'help', 'group'],
            $keys,
        );
    }
}
