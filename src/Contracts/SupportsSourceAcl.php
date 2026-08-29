<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorBase\Contracts;

use Padosoft\AskMyDocsConnectorBase\Access\SourceAccess;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorAuthException;

/**
 * Optional capability for a connector that can read who may see an item at
 * the source.
 *
 * Every source has a permission model — Drive sharing, Confluence space
 * restrictions, Jira issue security levels, Notion page permissions — and
 * none of it survived ingestion. A file shared with three people became, on
 * ingest, readable by everyone with the project. Nothing in the code was
 * wrong; the contract simply had nowhere to put the fact.
 *
 * A connector implementing this interface gives the host somewhere to put it.
 * The host:
 *   1. Detects the interface via instanceof (R23 — never a connector-name
 *      branch).
 *   2. Calls readAcl() for the item being ingested.
 *   3. Resolves the reported principals against its own directory and
 *      mirrors the outcome onto the document.
 *
 * Opt-in and backward compatible: a connector that does not implement it
 * behaves exactly as it does today, and `dispatchIngestion()` is UNCHANGED —
 * it takes no ACL argument and none is planned. The reported list travels
 * inside `$metadata` under {@see SourceAccess::METADATA_KEY}, which
 * {@see \Padosoft\AskMyDocsConnectorBase\BaseConnector::withSourceAccess()}
 * writes for you, so no call site handles the key by hand.
 *
 * The parameter was tried first and reverted: PHP rejects an implementation
 * declaring fewer parameters than its interface, so even an OPTIONAL trailing
 * one is a breaking change for every HOST that implements the contract — it
 * would fatal at class-declaration time on upgrade, before any of its own
 * code ran. Connectors are callers and would have been fine; hosts are not.
 *
 * **The connector reports; it never decides.** Returning a principal is not
 * granting access — the host owns the mapping from an external identifier to
 * an internal subject, because that depends on directory state the connector
 * cannot see. A connector that tried to decide would be answering a question
 * it lacks the information for.
 *
 * **Not knowing is a valid answer, and a different one from "nobody".** When
 * the source truncates the list, rate-limits, or refuses part of it, return
 * {@see SourceAccess::unknown()} rather than an empty allow-list. The host
 * treats those two differently on purpose: one is a fact about permissions,
 * the other is the absence of one.
 */
interface SupportsSourceAcl
{
    /**
     * The permissions the source reports for one item.
     *
     * @param  string  $remoteId  The connector's own identifier for the item,
     *                            the same one it uses when fetching content.
     *
     * @throws ConnectorAuthException
     *                                When the credentials no longer permit reading
     *                                permissions. Do NOT swallow this into an
     *                                empty list: a host that cannot tell an auth
     *                                failure from "no permissions" would quietly
     *                                unshare a document on every sync.
     */
    public function readAcl(string $remoteId): SourceAccess;
}
