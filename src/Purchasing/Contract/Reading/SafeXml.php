<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Contract\Reading;

/**
 * Parses the XML of a received file for a reader. The file comes from a stranger,
 * so the parser never resolves an external entity, never fetches the network,
 * refuses any DOCTYPE (an XML invoice has none) and refuses a file over 5 MB.
 * It is stateless and sits in the contract so that a reader of any module uses
 * the same rules.
 */
final class SafeXml
{
    public const int MAX_BYTES = 5 * 1024 * 1024;

    /**
     * @throws ReaderFailed when the bytes are empty, too big, not well-formed or have a DOCTYPE
     */
    public static function load(string $bytes): \DOMDocument
    {
        if ('' === trim($bytes)) {
            throw new ReaderFailed('The XML file is empty.');
        }
        if (\strlen($bytes) > self::MAX_BYTES) {
            throw new ReaderFailed('The XML file is over the size limit.');
        }
        // A DOCTYPE written in UTF-16 or UTF-32 has NUL bytes between its letters, so they go before the search.
        if (false !== stripos(str_replace("\0", '', $bytes), '<!DOCTYPE')) {
            throw new ReaderFailed('The XML file has a DOCTYPE, which is not allowed.');
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument();
            // No LIBXML_NOENT (entities are never substituted), no LIBXML_DTDLOAD, and no network.
            $loaded = $document->loadXML($bytes, \LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded || $document->doctype instanceof \DOMDocumentType) {
            throw new ReaderFailed('The XML file is not well-formed or has a DOCTYPE.');
        }

        return $document;
    }
}
