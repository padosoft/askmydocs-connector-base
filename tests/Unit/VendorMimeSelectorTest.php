<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorBase\Tests\Unit;

use Padosoft\AskMyDocsConnectorBase\Support\Metadata\VendorMimeSelector;
use Padosoft\AskMyDocsConnectorBase\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class VendorMimeSelectorTest extends TestCase
{
    #[Test]
    public function google_drive_native_doc_maps_to_gdoc_mime(): void
    {
        $this->assertSame(
            VendorMimeSelector::MIME_DRIVE_GDOC,
            VendorMimeSelector::forGoogleDrive('application/vnd.google-apps.document'),
        );
    }

    #[Test]
    public function google_drive_pdf_passes_through(): void
    {
        $this->assertSame(
            VendorMimeSelector::MIME_GENERIC_PDF,
            VendorMimeSelector::forGoogleDrive('application/pdf'),
        );
    }

    #[Test]
    public function google_drive_unknown_mime_falls_back_to_markdown(): void
    {
        $this->assertSame(
            VendorMimeSelector::MIME_GENERIC_MARKDOWN,
            VendorMimeSelector::forGoogleDrive('text/plain'),
        );
        $this->assertSame(
            VendorMimeSelector::MIME_GENERIC_MARKDOWN,
            VendorMimeSelector::forGoogleDrive(null),
        );
    }

    #[Test]
    public function onedrive_office_modern_resolves_to_office_mime(): void
    {
        $this->assertSame(
            VendorMimeSelector::MIME_ONEDRIVE_OFFICE,
            VendorMimeSelector::forOneDrive(
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            ),
        );
    }

    #[Test]
    public function onedrive_legacy_office_resolves_to_office_mime(): void
    {
        foreach (['application/msword', 'application/vnd.ms-excel', 'application/vnd.ms-powerpoint'] as $mime) {
            $this->assertSame(
                VendorMimeSelector::MIME_ONEDRIVE_OFFICE,
                VendorMimeSelector::forOneDrive($mime),
                "Failed for {$mime}",
            );
        }
    }

    #[Test]
    public function onedrive_pdf_passes_through(): void
    {
        $this->assertSame(
            VendorMimeSelector::MIME_GENERIC_PDF,
            VendorMimeSelector::forOneDrive('application/pdf'),
        );
    }
}
