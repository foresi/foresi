<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Infrastructure\Reading;

use Foresi\Purchasing\Contract\Reading\DocumentFile;
use Foresi\Purchasing\Contract\Reading\PdfFiles;
use Foresi\Purchasing\Contract\Reading\ReaderFailed;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Document;
use Smalot\PdfParser\Element\ElementArray;
use Smalot\PdfParser\Header;
use Smalot\PdfParser\Page;
use Smalot\PdfParser\Parser;
use Smalot\PdfParser\PDFObject;
use Smalot\PdfParser\XObject\Image;

/**
 * Reads attached files and page images in pure PHP with smalot/pdfparser, so
 * the server needs no system tool and no imaging extension beyond GD.
 *
 * Limits: a PDF over 10 MB is refused; the parser decodes at most 64 MB of
 * one stream; at most 20 attached files of up to 10 MB each and 20 images of up
 * to 25 million pixels are returned. An encrypted PDF is refused, never opened.
 * Pages are not rendered, so a code drawn with vectors yields no image.
 */
final readonly class SmalotPdfFiles implements PdfFiles
{
    /** The upload limit of the API; smalot parses every object of the file eagerly, so memory grows with the size. */
    private const int MAX_PDF_BYTES = 10 * 1024 * 1024;
    private const int MAX_DECODED_BYTES = 64 * 1024 * 1024;
    private const int MAX_EMBEDDED_FILES = 20;
    private const int MAX_EMBEDDED_BYTES = 10 * 1024 * 1024;
    private const int MAX_IMAGES = 20;
    private const int MAX_PIXELS = 25_000_000;

    public function embedded(DocumentFile $pdf): array
    {
        $document = $this->parse($pdf);
        $files = [];
        try {
            foreach ($document->getObjectsByType('Filespec') as $specification) {
                $file = $specification instanceof PDFObject ? $this->attachment($specification) : null;
                if ($file instanceof DocumentFile) {
                    $files[] = $file;
                }
                if (self::MAX_EMBEDDED_FILES === \count($files)) {
                    break;
                }
            }
        } catch (ReaderFailed $failed) {
            throw $failed;
        } catch (\Throwable) {
            throw new ReaderFailed('The attached files of the PDF cannot be read.');
        }

        return $files;
    }

    public function pageImages(DocumentFile $pdf, int $maxPages): array
    {
        $document = $this->parse($pdf);
        $images = [];
        $seen = [];
        try {
            foreach (\array_slice($document->getPages(), 0, max(0, $maxPages)) as $page) {
                $drawn = $this->drawnNames($page);
                foreach ($page->getXObjects() as $name => $object) {
                    // Resources may be shared by every page, so only what the page draws counts.
                    if (!$object instanceof Image || isset($seen[spl_object_id($object)]) || (null !== $drawn && !isset($drawn[(string) $name]))) {
                        continue;
                    }
                    $seen[spl_object_id($object)] = true;
                    $png = $this->png($object);
                    if (null !== $png) {
                        $images[] = $png;
                    }
                    if (self::MAX_IMAGES === \count($images)) {
                        return $images;
                    }
                }
            }
        } catch (\Throwable) {
            throw new ReaderFailed('The images of the PDF cannot be read.');
        }

        return $images;
    }

    /**
     * The names of the XObjects the content of the page draws (`/Name Do`), or null
     * when the content cannot be read, which counts every image in its resources.
     *
     * @return array<string, true>|null
     */
    private function drawnNames(Page $page): ?array
    {
        $contents = $page->get('Contents');
        $listed = $contents instanceof ElementArray ? $contents->getContent() : [];
        $streams = $contents instanceof PDFObject ? [$contents] : (\is_array($listed) ? $listed : []);
        $text = '';
        foreach ($streams as $stream) {
            if (!$stream instanceof PDFObject) {
                return null;
            }
            $text .= ' '.$stream->getContent();
        }
        if ('' === trim($text)) {
            return null;
        }
        preg_match_all('~/([^\s/<>\[\]()]+)\s+Do(?![A-Za-z])~', $text, $found);

        return array_fill_keys($found[1], true);
    }

    private function parse(DocumentFile $pdf): Document
    {
        if (\strlen($pdf->bytes) > self::MAX_PDF_BYTES) {
            throw new ReaderFailed('The PDF is over the size limit.');
        }
        if (!str_contains(substr($pdf->bytes, 0, 1024), '%PDF-')) {
            throw new ReaderFailed('The file is not a PDF.');
        }
        $config = new Config();
        $config->setDecodeMemoryLimit(self::MAX_DECODED_BYTES);
        $config->setMaxNestingDepth(200);

        try {
            return new Parser([], $config)->parseContent($pdf->bytes);
        } catch (\Throwable $error) {
            // Depends on smalot's wording ("Secured pdf file ..."); covered by the encrypted-PDF tests.
            // The parser refuses an encrypted PDF with a message about "secured" files.
            throw new ReaderFailed(str_contains(strtolower($error->getMessage()), 'secured') ? 'The PDF is encrypted.' : 'The PDF is corrupt.', 0, $error);
        }
    }

    private function attachment(PDFObject $specification): ?DocumentFile
    {
        $details = $specification->getHeader()?->getDetails() ?? [];
        $name = $details['UF'] ?? $details['F'] ?? null;
        $files = $specification->get('EF');
        $stream = $files instanceof Header ? $files->get('F') : null;
        if (!\is_string($name) || '' === $name || !$stream instanceof PDFObject) {
            return null;
        }
        $bytes = $stream->getContent();
        if (null === $bytes || '' === $bytes || \strlen($bytes) > self::MAX_EMBEDDED_BYTES) {
            return null;
        }
        $subtype = $stream->getHeader()?->getDetails()['Subtype'] ?? '';

        return new DocumentFile(basename($name), \is_string($subtype) ? str_replace('#2F', '/', $subtype) : '', $bytes);
    }

    /**
     * The image as PNG bytes, or null when it is a kind this adapter does not convert
     * (a palette, a mask, a format other than JPEG, or raw 1-, 8-bit gray and 8-bit RGB).
     */
    private function png(Image $image): ?string
    {
        /** @var array<string, mixed> $details */
        $details = $image->getHeader()?->getDetails() ?? [];
        $width = $this->integer($details['Width'] ?? null);
        $height = $this->integer($details['Height'] ?? null);
        $content = $image->getContent();
        if ($width < 1 || $height < 1 || $width * $height > self::MAX_PIXELS || null === $content || '' === $content) {
            return null;
        }
        $filters = array_values(array_filter((array) ($details['Filter'] ?? []), \is_string(...)));

        if (\in_array('DCTDecode', $filters, true)) {
            return $this->jpegToPng($content);
        }
        if ([] !== array_diff($filters, ['FlateDecode'])) {
            return null;
        }

        return $this->rawToPng($details, $width, $height, $content);
    }

    private function jpegToPng(string $jpeg): ?string
    {
        $size = @getimagesizefromstring($jpeg);
        if (false === $size || $size[0] * $size[1] > self::MAX_PIXELS) {
            return null;
        }
        $image = @imagecreatefromstring($jpeg);
        if (false === $image) {
            return null;
        }
        ob_start();
        $written = imagepng($image);
        $png = (string) ob_get_clean();

        return $written ? $png : null;
    }

    /**
     * @param array<string, mixed> $details the header of the image
     * @param string               $rows    the decoded stream: the rows of pixels, each led by a PNG filter byte when the image uses a PNG predictor
     */
    private function rawToPng(array $details, int $width, int $height, string $rows): ?string
    {
        $bits = $this->integer($details['BitsPerComponent'] ?? null);
        $space = $details['ColorSpace'] ?? null;
        [$colorType, $colors] = match (true) {
            'DeviceGray' === $space && \in_array($bits, [1, 8], true) => [0, 1],
            'DeviceRGB' === $space && 8 === $bits => [2, 3],
            default => [null, 0],
        };
        if (null === $colorType) {
            return null;
        }
        $parameters = $details['DecodeParms'] ?? [];
        $predictor = \is_array($parameters) ? $this->integer($parameters['Predictor'] ?? 1) : 1;
        $rowLength = intdiv($width * $colors * $bits + 7, 8);

        if ($predictor >= 10) {
            if (\strlen($rows) !== ($rowLength + 1) * $height) {
                return null;
            }
        } elseif (1 === $predictor && \strlen($rows) === $rowLength * $height) {
            $filtered = '';
            foreach (str_split($rows, max(1, $rowLength)) as $row) {
                $filtered .= "\0".$row;
            }
            $rows = $filtered;
        } else {
            return null;
        }
        $compressed = gzcompress($rows);
        if (false === $compressed) {
            return null;
        }
        $header = pack('NNCCCCC', $width, $height, $bits, $colorType, 0, 0, 0);

        return "\x89PNG\r\n\x1a\n".$this->chunk('IHDR', $header).$this->chunk('IDAT', $compressed).$this->chunk('IEND', '');
    }

    private function integer(mixed $value): int
    {
        return \is_int($value) || (\is_string($value) && ctype_digit($value)) ? (int) $value : 0;
    }

    private function chunk(string $type, string $data): string
    {
        return pack('N', \strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }
}
