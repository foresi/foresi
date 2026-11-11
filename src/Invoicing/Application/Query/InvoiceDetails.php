<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application\Query;

use App\Invoicing\Application\InvoiceHistory;
use App\Invoicing\Application\InvoicePayments;
use App\Invoicing\Application\InvoiceStore;
use App\Invoicing\Application\InvoicingAccess;
use App\Invoicing\Application\InvoicingNotAllowed;
use App\Invoicing\Application\UnknownInvoice;
use App\Shared\Domain\Id;

/**
 * One invoice for its pages, the API and MCP: the document with its lines, payments,
 * balances, corrective documents and history. Only the owners and members of the
 * organisation read it, in the trash too.
 */
final readonly class InvoiceDetails
{
    public function __construct(
        private InvoicingAccess $access,
        private InvoiceStore $store,
        private InvoicePayments $payments,
        private InvoiceHistory $history,
    ) {
    }

    /**
     * @throws InvoicingNotAllowed for anyone but the owners and members of the organisation
     * @throws UnknownInvoice      also for an invoice of another tenant, and for an id that is none
     */
    public function of(string $id): InvoiceDetail
    {
        $this->access->denyUnlessStaff();
        $invoice = Id::isValid($id) ? $this->store->get($id) : throw new UnknownInvoice();

        return new InvoiceDetail(
            $invoice,
            $this->store->lines($invoice),
            $this->payments->linesOf($invoice),
            $this->store->correctionsOf($invoice),
            $this->payments->paidOf($invoice),
            $this->payments->correctionsOf($invoice),
            $this->payments->remainingOf($invoice),
            $this->payments->toRefundOf($invoice),
            $this->history->of($invoice),
        );
    }
}
