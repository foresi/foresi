<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Support;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Common\Version;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Com\Tecnick\Pdf\AFRelationship;
use Com\Tecnick\Pdf\Encrypt\Encrypt;
use Com\Tecnick\Pdf\Tcpdf;

/**
 * Builds the files a received document arrives as, for the tests of the
 * readers: a QR code as a PNG, and PDFs made with tc-lib-pdf, the repository's
 * PDF writer. Nothing here is a real document.
 */
final class ReceivedFileFixtures
{
    /**
     * A QR code with a quiet zone, as a PNG.
     */
    public static function qrPng(string $payload, int $scale = 6): string
    {
        $options = new QROptions();
        $options->version = Version::AUTO;
        $options->eccLevel = EccLevel::M;
        $options->outputInterface = QRGdImagePNG::class;
        $options->outputBase64 = false;
        $options->scale = $scale;
        $options->quietzoneSize = 4;

        $png = new QRCode($options)->render($payload);
        if (!\is_string($png)) {
            throw new \LogicException('The QR code is not rendered as bytes.');
        }

        return $png;
    }

    /**
     * A PDF of $pages pages.
     *
     * @param array<int, string> $images       image bytes by page number (from 1), each placed on its page
     * @param string|null        $embeddedName the name of one file attached to the document
     */
    public static function pdf(int $pages = 1, array $images = [], ?Encrypt $encryption = null, ?string $embeddedName = null, string $embeddedContent = ''): string
    {
        $pdf = new Tcpdf(unit: 'mm', isunicode: false, compress: true, objEncrypt: $encryption, fileOptions: ['allowedHosts' => [], 'allowedPaths' => []]);
        for ($page = 1; $page <= $pages; ++$page) {
            $pdf->addPage(['width' => 210, 'height' => 297]);
            if (isset($images[$page])) {
                $imageId = $pdf->image->add('@'.$images[$page]);
                $pdf->page->addContent($pdf->image->getSetImage($imageId, 20.0, 20.0, 60.0, 60.0, 297.0));
            }
        }
        if (null !== $embeddedName) {
            $pdf->addContentAsEmbeddedFile($embeddedName, $embeddedContent, 'application/xml', AFRelationship::Alternative);
        }

        return $pdf->getOutPDFString();
    }

    public static function encryption(): Encrypt
    {
        return new Encrypt(enabled: true, mode: 2, user_pass: 'example', owner_pass: 'example-owner');
    }
}
