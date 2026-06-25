<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorBase\Contracts;

use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorApiException;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorAuthException;

/**
 * Optional capability interface for connectors that can enumerate the LIVE
 * containers an operator may whitelist/blacklist for sync — IMAP folders, Gmail
 * labels, Notion spaces, Drive shared-drives, etc.
 *
 * A connector that implements this interface advertises to the AskMyDocs host
 * that it owns the "what containers exist right now?" question — so the host
 * does NOT have to reconstruct the upstream client itself. The host:
 *   1. Detects the interface via instanceof (R23 — no `if ($name === 'imap')` branch).
 *   2. Calls listAvailableFolders() to populate a "pick folders to sync" UI.
 *   3. Renders the returned identifiers verbatim and round-trips the picked
 *      values back into the connector's own config (e.g. config_json.folders.include),
 *      so a chosen value matches 1:1 what the connector later filters on.
 *
 * The connector owns the auth + client lifecycle entirely (token refresh,
 * connect, close) — that is the whole point of this seam: the host never sees
 * how the connector authenticates. Tenant scoping is the connector's
 * responsibility too (it resolves the installation through its own
 * tenant-scoped lookup; the host has already enforced R30 before reaching here).
 *
 * Connectors with no notion of selectable containers simply do NOT implement
 * this interface; the host treats discovery as unavailable for them. Pair this
 * with a {@see SupportsConnectionSettings} 'multiselect' field whose `discovery`
 * names this source, so the settings editor offers the live list.
 */
interface SupportsFolderDiscovery
{
    /**
     * The live, verbatim container identifiers for the installation — exactly
     * the values the connector's own sync filter whitelists/blacklists (so a
     * picked value round-trips 1:1 with config_json).
     *
     * Read-only with respect to the SOURCE and the operator's configuration: it
     * MUST NOT mutate config_json, change upstream state, or ingest anything. It
     * MAY persist a credential refresh performed as part of the connector-owned
     * auth lifecycle (e.g. rotating an OAuth2 access/refresh token while
     * connecting) — that is an internal side effect of authenticating, not a
     * change to what the operator configured, and is exactly why discovery lives
     * in the connector rather than the host. An empty list is a valid result (the
     * source genuinely has no containers) and MUST be distinguishable from a
     * failure — the latter throws.
     *
     * The two failure types mirror the base exception taxonomy so the host can
     * react correctly (transient → retryable / re-fetchable, auth → operator must
     * re-authenticate). A discovery call is operator-driven, so the host typically
     * catches both and surfaces an actionable error; implementors must still pick
     * the right type rather than collapsing auth failures into an API outage.
     *
     * @return list<string>
     *
     * @throws ConnectorApiException when the source is unreachable, the upstream
     *                               is transiently down, or the response is
     *                               malformed — a NON-auth failure. The host maps
     *                               it to a 503-class "couldn't reach the source"
     *                               error, never a misleading empty-but-successful
     *                               list (R14).
     * @throws ConnectorAuthException when the stored credentials are rejected
     *                                (401/403) or an OAuth token refresh fails — the
     *                                operator must re-authenticate. Kept distinct
     *                                from ConnectorApiException so an auth failure is
     *                                never mistaken for a transient outage.
     */
    public function listAvailableFolders(int $installationId): array;
}
