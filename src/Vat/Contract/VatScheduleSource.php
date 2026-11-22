<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A module whose schedules will give documents with VAT, such as Invoicing
 * with its recurring invoices. A schedule counts only what is not issued
 * yet: an occurrence that is issued is a document and counts through its
 * VatDocumentSource. The VAT module owns the contract and the module
 * implements it in its own Integration/Vat directory (ADR 0033).
 */
#[AutoconfigureTag(self::TAG)]
interface VatScheduleSource
{
    public const string TAG = 'app.vat_schedule_source';

    /**
     * A stable key, such as "invoicing.recurring_vat".
     */
    public function key(): string;

    /**
     * Whether the caller may see the schedules behind the amounts; without it
     * only the totals are shown.
     */
    public function mayReadDetails(): bool;

    /**
     * The VAT of the occurrences not issued yet whose tax point falls on the
     * days from $from to $to, both included, in the time zone of $from.
     *
     * @return iterable<ScheduledVat>
     */
    public function between(\DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $today): iterable;
}
