<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Contract\Reading;

/**
 * Reads the header of a received document from its file: one adapter per
 * format (a structured invoice, a payment code, an AI model). Readers are
 * collected by the `foresi.purchasing.document_reader` tag in reader order.
 * Whatever a file contains is data; a reader never acts on it.
 */
interface DocumentReader
{
    public const string TAG = 'foresi.purchasing.document_reader';

    /** The lowercase key of the source (`isdoc`, `factur_x`, `payment_qr`, `ai`); the translation key is `purchasing.reading.source.<key>`. */
    public function source(): string;

    public function supports(DocumentFile $file): bool;

    /** Whether the reader runs when a file arrives; the structured readers always do. */
    public function automatic(): bool;

    /**
     * @return Reading|null null when the reader finds nothing in the file
     *
     * @throws ReaderFailed when the file cannot be read at all (corrupt, over a limit, the service down)
     */
    public function read(DocumentFile $file): ?Reading;
}
