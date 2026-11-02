<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\Invoice;

/**
 * The one status a document shows, always derived and never stored: the most relevant
 * of what is true of it. The trash outranks everything, and what is overdue outranks
 * a delivery, because that is what someone has to act on. Payment states will join
 * this order; they do not get a column of their own.
 */
enum InvoiceStatus: string
{
    case Issued = 'issued';
    case Delivered = 'delivered';
    case Overdue = 'overdue';
    case Trashed = 'trashed';

    public static function of(bool $delivered, bool $overdue, bool $trashed): self
    {
        return match (true) {
            $trashed => self::Trashed,
            $overdue => self::Overdue,
            $delivered => self::Delivered,
            default => self::Issued,
        };
    }

    /**
     * @param \DateTimeImmutable $today in the time zone of the country that issued the document
     */
    public static function ofInvoice(Invoice $invoice, \DateTimeImmutable $today): self
    {
        return self::of($invoice->isDelivered(), $invoice->dueDate()->format('Y-m-d') < $today->format('Y-m-d'), $invoice->isTrashed());
    }

    public function tone(): string
    {
        return match ($this) {
            self::Issued => 'gray',
            self::Delivered => 'green',
            self::Overdue => 'red',
            self::Trashed => 'amber',
        };
    }
}
