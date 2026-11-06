<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\DocumentKind;
use App\Party\Domain\LegalEntity;
use App\Shared\Domain\Currency;

/**
 * How a pack for the accountant names its folders and files, in the language of
 * the person who downloads it, and how it writes the list of its documents.
 */
interface AccountantPackFormat
{
    public function packName(LegalEntity $firm, \DateTimeImmutable $month): string;

    public function folder(DocumentKind $kind, string $locale): string;

    /**
     * The name of a document's files without the extension.
     */
    public function baseName(IssuedDocument $document, string $locale): string;

    public function listName(string $locale): string;

    /**
     * @param list<AccountantDocument> $documents
     * @param list<VatRecapLine>       $recap
     */
    public function writeList(string $path, array $documents, array $recap, Currency $home, string $locale): void;
}
