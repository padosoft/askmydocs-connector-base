<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorBase\Support;

use Padosoft\AskMyDocsConnectorBase\Contracts\SupportsConnectionSettings;
use Padosoft\AskMyDocsConnectorBase\Contracts\SupportsCredentialForm;
use Padosoft\AskMyDocsConnectorBase\Contracts\SupportsFolderDiscovery;

/**
 * Describes a single form field the AskMyDocs host renders when configuring
 * a credential-based connector (e.g. IMAP, SMTP, custom API key flows) — or
 * one editable post-install sync setting (see {@see SupportsConnectionSettings}).
 *
 * Build an array of these via {@see SupportsCredentialForm::credentialFormSchema()}
 * or {@see SupportsConnectionSettings::connectionSettingsSchema()},
 * then call {@see toArray()} on each to produce the JSON-serialisable shape the host consumes.
 */
final class CredentialField
{
    /**
     * @param  string  $name  Field key. For credential forms it is the submitted form-data key (e.g. 'host').
     *                        For a connection-settings schema it is the dotted path under config_json the value is
     *                        written to via the host's array set (e.g. 'folders.include' → config_json['folders']['include'],
     *                        'date_window_days' → config_json['date_window_days']).
     * @param  string  $label  Human-readable label shown in the admin UI.
     * @param  string  $type  HTML-like input type. One of:
     *                        'text' | 'number' | 'password' | 'select' | 'checkbox'
     *                        | 'multiselect' (a list of values chosen from a fixed or live option set — see $discovery)
     *                        | 'tags' (an open-ended list of free-text string values).
     *                        Both 'multiselect' and 'tags' serialise as a JSON array; the host stores them verbatim.
     * @param  string  $target  Where the host stores the submitted value:
     *                          'connection' | 'config'  → connector_installations.config_json[name] (config: dotted name = nested path)
     *                          'auth_mode'              → installation's auth_mode column
     *                          'provider'               → installation's provider identifier
     *                          'secret'                 → routed through handleOAuthCallback, stored in the vault (never in config_json)
     * @param  bool  $required  Whether the field must be filled before the form can be submitted.
     * @param  bool  $secret  When true the value is treated as a credential: masked in the UI and
     *                        never persisted in config_json. The host routes it through handleOAuthCallback
     *                        and stores it encrypted in the credential vault. Connection-settings fields are NEVER secret.
     * @param  mixed  $default  Pre-filled default value. Must be JSON-serialisable. For 'multiselect'/'tags' a list.
     * @param  array<string,string>  $options  For type='select'/'multiselect' with a FIXED option set:
     *                                         associative map of value → display label. Leave empty for a 'multiselect'
     *                                         whose options are live (set $discovery instead) or for 'tags'.
     * @param  array{field:string,equals:string}|null  $showIf  Conditional display rule:
     *                                                          show this field only when another field equals the given value.
     * @param  string|null  $help  Short helper / hint text rendered below the field.
     * @param  string|null  $group  Optional UI group / section heading for visual grouping.
     * @param  string|null  $discovery  For a 'multiselect' whose options are LIVE rather than fixed: names the
     *                                  discovery source the host queries to populate the choices — e.g. 'folders'
     *                                  → the connector's {@see SupportsFolderDiscovery::listAvailableFolders()}.
     *                                  Kept a free string (not an enum) so future sources (labels, spaces, drives) need no base change.
     *                                  null = the field's options are fixed (from $options) or free-text ('tags').
     *                                  Ignored by the host for any type other than 'multiselect'.
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
        public readonly ?string $discovery = null,
    ) {}

    /**
     * Returns all properties as a plain, JSON-serialisable associative array.
     * This is the shape the AskMyDocs host receives from credentialFormSchema()
     * / connectionSettingsSchema().
     *
     * `discovery` is appended last (additive — pre-v1.4 consumers that read by
     * key are unaffected; it is simply null on every legacy field).
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
            'discovery' => $this->discovery,
        ];
    }
}
