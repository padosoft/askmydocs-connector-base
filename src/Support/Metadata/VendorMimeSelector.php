<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorBase\Support\Metadata;

/**
 * Picks the synthetic vendor MIME type that routes a connector-produced
 * document to the right source-aware chunker on the host side.
 *
 * The string constants here are part of the connector ↔ host contract.
 * If a host changes them (e.g. host's `config/kb-pipeline.php` shapes
 * them differently), it MUST publish a custom MIME map in its own
 * connector layer rather than divergent constants here.
 */
final class VendorMimeSelector
{
    public const MIME_NOTION_PAGE = 'application/vnd.notion.page+json';

    public const MIME_NOTION_NOTE = 'application/vnd.notion.note+json';

    public const MIME_CONFLUENCE_PAGE = 'application/vnd.confluence.page+json';

    public const MIME_JIRA_ISSUE = 'application/vnd.jira.issue+json';

    public const MIME_EVERNOTE_NOTE = 'application/vnd.evernote.note+xml';

    public const MIME_FABRIC_NOTE = 'application/vnd.fabric.note+json';

    public const MIME_DRIVE_GDOC = 'application/vnd.google-apps.document';

    public const MIME_DRIVE_GSHEET = 'application/vnd.google-apps.spreadsheet';

    public const MIME_DRIVE_GSLIDE = 'application/vnd.google-apps.presentation';

    public const MIME_ONEDRIVE_OFFICE = 'application/vnd.onedrive.office+json';

    public const MIME_GENERIC_MARKDOWN = 'text/markdown';

    public const MIME_GENERIC_PDF = 'application/pdf';

    /**
     * Google Drive MIME → source-aware vendor MIME. Falls back to plain
     * markdown for everything we don't classify (the markdown was
     * already rendered by the connector, so the generic chunker still
     * works — just without the source-aware enrichment).
     */
    public static function forGoogleDrive(?string $googleMimeType): string
    {
        return match ($googleMimeType) {
            'application/vnd.google-apps.document' => self::MIME_DRIVE_GDOC,
            'application/vnd.google-apps.spreadsheet' => self::MIME_DRIVE_GSHEET,
            'application/vnd.google-apps.presentation' => self::MIME_DRIVE_GSLIDE,
            'application/pdf' => self::MIME_GENERIC_PDF,
            default => self::MIME_GENERIC_MARKDOWN,
        };
    }

    /**
     * OneDrive content-type → vendor MIME. OneDrive returns one of:
     *   * `application/vnd.openxmlformats-officedocument.*` (modern Office)
     *   * `application/msword` / xls (legacy Office)
     *   * `application/pdf`
     *   * `text/markdown`
     */
    public static function forOneDrive(?string $contentType): string
    {
        if ($contentType === null) {
            return self::MIME_GENERIC_MARKDOWN;
        }
        if (str_starts_with($contentType, 'application/vnd.openxmlformats-officedocument')) {
            return self::MIME_ONEDRIVE_OFFICE;
        }

        return match ($contentType) {
            'application/msword',
            'application/vnd.ms-excel',
            'application/vnd.ms-powerpoint' => self::MIME_ONEDRIVE_OFFICE,
            'application/pdf' => self::MIME_GENERIC_PDF,
            default => self::MIME_GENERIC_MARKDOWN,
        };
    }
}
