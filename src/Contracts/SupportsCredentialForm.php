<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorBase\Contracts;

use Padosoft\AskMyDocsConnectorBase\Support\CredentialField;

/**
 * Optional capability interface for credential-based connectors.
 *
 * A connector that implements this interface advertises to the AskMyDocs host
 * that it can be configured via a native admin credential form, instead of (or
 * in addition to) an OAuth-redirect flow. Typical use cases: IMAP/SMTP connectors,
 * API-key-based connectors, or any provider that does not support OAuth 2.0.
 *
 * When a connector implements this interface the host will:
 *   1. Detect the interface via instanceof at install time.
 *   2. Call credentialFormSchema() to retrieve the ordered field definitions.
 *   3. Render a native admin form with those fields.
 *   4. Route each submitted value according to the field's `target`:
 *        - 'connection' / 'config'  → persisted in connector_installations.config_json under the field name
 *        - 'auth_mode'              → stored as the installation's auth_mode value
 *        - 'provider'               → stored as the installation's provider identifier
 *        - 'secret'                 → never stored in config_json; the host routes it through
 *                                     handleOAuthCallback() and stores it encrypted in the vault
 *
 * Connectors that use only the standard OAuth redirect flow do NOT implement this interface.
 * Implementing it is entirely opt-in and backward compatible — the host falls back to the
 * OAuth flow for any connector that does not implement SupportsCredentialForm.
 *
 * @see CredentialField
 */
interface SupportsCredentialForm
{
    /**
     * Schema of fields the host should render to configure/connect this connector.
     *
     * Each entry is the array shape produced by
     * {@see CredentialField::toArray()}.
     * Fields are rendered in declaration order.
     *
     * Non-secret fields (secret:false) are stored by the host in
     * connector_installations.config_json keyed by their `name`.
     * The single secret field (secret:true), if present, is routed through
     * handleOAuthCallback() and stored encrypted in the credential vault.
     *
     * @return list<array<string,mixed>>
     */
    public function credentialFormSchema(): array;
}
