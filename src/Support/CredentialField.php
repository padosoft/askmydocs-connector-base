<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorBase\Support;

use Padosoft\AskMyDocsConnectorBase\Contracts\SupportsCredentialForm;

/**
 * Describes a single form field the AskMyDocs host renders when configuring
 * a credential-based connector (e.g. IMAP, SMTP, custom API key flows).
 *
 * Build an array of these via {@see SupportsCredentialForm::credentialFormSchema()},
 * then call {@see toArray()} on each to produce the JSON-serialisable shape the host consumes.
 */
final class CredentialField
{
    /**
     * @param  string  $name  Field key used as the submitted form-data key (e.g. 'host').
     * @param  string  $label  Human-readable label shown in the admin UI.
     * @param  string  $type  HTML-like input type: 'text'|'number'|'password'|'select'|'checkbox'.
     * @param  string  $target  Where the host stores the submitted value:
     *                          'connection' | 'config'  → connector_installations.config_json[name]
     *                          'auth_mode'              → installation's auth_mode column
     *                          'provider'               → installation's provider identifier
     *                          'secret'                 → routed through handleOAuthCallback, stored in the vault (never in config_json)
     * @param  bool  $required  Whether the field must be filled before the form can be submitted.
     * @param  bool  $secret  When true the value is treated as a credential: masked in the UI and
     *                        never persisted in config_json. The host routes it through handleOAuthCallback
     *                        and stores it encrypted in the credential vault.
     * @param  mixed  $default  Pre-filled default value. Must be JSON-serialisable.
     * @param  array<string,string>  $options  For type='select': associative map of value → display label.
     * @param  array{field:string,equals:string}|null  $showIf  Conditional display rule:
     *                                                          show this field only when another field equals the given value.
     * @param  string|null  $help  Short helper / hint text rendered below the field.
     * @param  string|null  $group  Optional UI group / section heading for visual grouping.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $type,
        public readonly string $target,
        public readonly bool $required = false,
        public readonly bool $secret = false,
        public readonly mixed $default = null,
        public readonly array $options = [],
        public readonly ?array $showIf = null,
        public readonly ?string $help = null,
        public readonly ?string $group = null,
    ) {}

    /**
     * Returns all properties as a plain, JSON-serialisable associative array.
     * This is the shape the AskMyDocs host receives from credentialFormSchema().
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->label,
            'type' => $this->type,
            'target' => $this->target,
            'required' => $this->required,
            'secret' => $this->secret,
            'default' => $this->default,
            'options' => $this->options,
            'showIf' => $this->showIf,
            'help' => $this->help,
            'group' => $this->group,
        ];
    }
}
