<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A module whose documents carry VAT: Invoicing for the documents it issues,
 * Purchasing for the ones it receives. The VAT module owns the contract and
 * the module implements it in its own Integration/Vat directory (ADR 0033),
 * so the VAT module works without it.
 */
#[AutoconfigureTag(self::TAG)]
interface VatDocumentSource
{
    public const string TAG = 'app.vat_document_source';

    /**
     * A stable key, such as "invoicing.issued_vat".
     */
    public function key(): string;

    /**
     * Whether the caller may see the documents behind the amounts; without it
     * only the totals are shown.
     */
    public function mayReadDetails(): bool;

    /**
     * The VAT of the documents that count on the days from $from to $to, both
     * included, in the time zone of $from.
     *
     * @return iterable<DocumentVat>
     */
    public function between(\DateTimeImmutable $from, \DateTimeImmutable $to): iterable;
}
