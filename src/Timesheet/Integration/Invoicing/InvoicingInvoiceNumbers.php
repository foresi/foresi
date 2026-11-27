<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Timesheet\Integration\Invoicing;

use Foresi\Invoicing\Contract\InvoiceNumbers as Numbers;
use Foresi\Shared\Domain\Id;
use Foresi\Timesheet\Infrastructure\InvoiceNumbers;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The numbers of the invoices that billed time, as Invoicing gives them, for
 * the time screens to name them, and the invoice's page for them to link it.
 */
final readonly class InvoicingInvoiceNumbers implements InvoiceNumbers
{
    public function __construct(private Numbers $numbers, private UrlGeneratorInterface $urls)
    {
    }

    public function numbersOf(array $invoiceIds): array
    {
        return $this->numbers->numbersOf($invoiceIds);
    }

    public function url(string $invoiceId): ?string
    {
        return Id::isValid($invoiceId) ? $this->urls->generate('app_invoice_edit', ['id' => $invoiceId]) : null;
    }
}
