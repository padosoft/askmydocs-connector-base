<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorBase\Contracts;

use Padosoft\AskMyDocsConnectorBase\Support\CredentialField;

/**
 * Optional capability interface for connectors that expose EDITABLE post-install
 * sync-behaviour settings — distinct from {@see SupportsCredentialForm}, which
 * describes the connect-time credential form.
 *
 * `credentialFormSchema()` answers "what do I need to CONNECT?" (host, port,
 * username, password / OAuth). `connectionSettingsSchema()` answers "what can the
 * operator tune about HOW I sync, once connected?" — the sync window, which
 * folders/labels to include or exclude, sender/recipient/subject filters, body
 * format, attachment limits, and so on. These are the knobs the connector reads
 * from `config_json` at sync time; this interface makes the host render them in a
 * generic, schema-driven settings editor instead of every connector hand-rolling
 * a bespoke form (R23 — no connector-name branch in the host).
 *
 * When a connector implements this interface the host will:
 *   1. Detect it via instanceof at the connector-settings surface.
 *   2. Call connectionSettingsSchema() to retrieve the ordered field definitions.
 *   3. Render a native settings form (reusing the same field renderer as the
 *      credential form) seeded with the installation's current config_json values.
 *   4. Persist each submitted value into config_json at the field's `name`
 *      (a dotted path → a nested key, e.g. 'folders.include' → config_json['folders']['include']).
 *
 * Every field MUST use `target='config'` and MUST NOT be secret — settings are
 * plain configuration, never credentials. A 'multiselect' field whose options
 * are live (folders/labels/…) sets its `discovery` to the source name and the
 * connector must also implement the matching discovery capability
 * (e.g. {@see SupportsFolderDiscovery} for `discovery='folders'`).
 *
 * Implementing this interface is entirely opt-in and backward compatible — the
 * host simply offers no settings editor for a connector that does not implement it.
 *
 * @see CredentialField
 */
interface SupportsConnectionSettings
{
    /**
     * Schema of the editable post-install sync settings the host should render.
     *
     * Each entry is the array shape produced by {@see CredentialField::toArray()}.
     * Fields are rendered in declaration order and grouped by their `group`.
     * The host seeds each field from the installation's current config_json value
     * (falling back to the field `default`) and writes submitted values back into
     * config_json keyed by the field `name` (dotted = nested).
     *
     * @return list<array<string,mixed>>
     */
    public function connectionSettingsSchema(): array;
}
