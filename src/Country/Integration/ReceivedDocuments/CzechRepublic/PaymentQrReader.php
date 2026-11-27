<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Country\Integration\ReceivedDocuments\CzechRepublic;

use chillerlan\QRCode\Common\GDLuminanceSource;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QRCodeException;
use chillerlan\QRCode\QROptions;
use Foresi\ReceivedDocuments\Contract\Reading\DocumentFile;
use Foresi\ReceivedDocuments\Contract\Reading\DocumentReader;
use Foresi\ReceivedDocuments\Contract\Reading\PdfFiles;
use Foresi\ReceivedDocuments\Contract\Reading\ReaderFailed;
use Foresi\ReceivedDocuments\Contract\Reading\Reading;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Reads the QR payment code of an image or of the first pages of a PDF, and
 * uses the first code that holds a Czech payment descriptor.
 *
 * It decodes the images stored in a PDF (see PdfFiles). A code drawn with
 * vectors, as the PDF of an invoice system often has it, is not read.
 *
 * The priority is the reader order: the tag `foresi.received_documents.document_reader`
 * comes from the autoconfiguration of DocumentReader, and AsTaggedItem sets the
 * priority on it without a second tag.
 */
#[AsTaggedItem(priority: 200)]
final readonly class PaymentQrReader implements DocumentReader
{
    private const int PAGES = 2;
    private const int MAX_IMAGE_BYTES = 20 * 1024 * 1024;
    private const int MAX_PIXELS = 25_000_000;
    /** The longest side the decoder gets, in pixels. */
    private const int MAX_SIDE = 2000;

    public function __construct(
        private PdfFiles $pdfFiles,
        private SpaydParser $parser,
    ) {
    }

    public function source(): string
    {
        return 'payment_qr';
    }

    public function supports(DocumentFile $file): bool
    {
        return $file->isImage() || $file->isPdf();
    }

    public function automatic(): bool
    {
        return true;
    }

    public function read(DocumentFile $file): ?Reading
    {
        $images = $file->isPdf() ? $this->pdfFiles->pageImages($file, self::PAGES) : [$this->checked($file->bytes)];
        foreach ($images as $image) {
            $reading = $this->readImage($image);
            if ($reading instanceof Reading) {
                return $reading;
            }
        }

        return null;
    }

    /**
     * @throws ReaderFailed when the image is over a limit or is not an image
     */
    private function checked(string $bytes): string
    {
        if (\strlen($bytes) > self::MAX_IMAGE_BYTES) {
            throw new ReaderFailed('The image is over the size limit.');
        }
        $size = @getimagesizefromstring($bytes);
        if (false === $size) {
            throw new ReaderFailed('The file is not an image.');
        }
        if ($size[0] * $size[1] > self::MAX_PIXELS) {
            throw new ReaderFailed('The image has too many pixels.');
        }

        return $bytes;
    }

    private function readImage(string $image): ?Reading
    {
        $size = @getimagesizefromstring($image);
        if (false === $size || $size[0] * $size[1] > self::MAX_PIXELS) {
            return null;
        }
        $gd = @imagecreatefromstring($image);
        if (false === $gd) {
            return null;
        }
        // The decoder holds several copies of every pixel, so it never sees more than about 4 Mpx.
        if (max($size[0], $size[1]) > self::MAX_SIDE) {
            $scaled = imagescale($gd, $size[0] >= $size[1] ? self::MAX_SIDE : (int) max(1, round($size[0] * self::MAX_SIDE / $size[1])), $size[1] > $size[0] ? self::MAX_SIDE : -1, \IMG_BILINEAR_FIXED);
            unset($gd);
            if (false === $scaled) {
                return null;
            }
            $gd = $scaled;
        }

        $options = new QROptions();
        $options->readerUseImagickIfAvailable = false;
        $options->readerIncreaseContrast = true;
        try {
            $text = (string) new QRCode($options)->readFromSource(new GDLuminanceSource($gd, $options));
        } catch (QRCodeException) {
            // No code in the image, or one that cannot be decoded: nothing to read here.
            return null;
        }

        return $this->parser->parse($text);
    }
}
