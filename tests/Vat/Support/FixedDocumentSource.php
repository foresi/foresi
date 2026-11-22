<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Vat\Support;

use Foresi\Vat\Contract\DocumentVat;
use Foresi\Vat\Contract\VatDocumentSource;

/**
 * Issued documents that answer the VAT they were given, whatever is asked.
 */
final readonly class FixedDocumentSource implements VatDocumentSource
{
    /**
     * @param list<DocumentVat> $documents
     */
    public function __construct(private array $documents)
    {
    }

    public function key(): string
    {
        return 'invoicing.issued_vat';
    }

    public function mayReadDetails(): bool
    {
        return true;
    }

    public function between(\DateTimeImmutable $from, \DateTimeImmutable $to): iterable
    {
        return $this->documents;
    }
}
