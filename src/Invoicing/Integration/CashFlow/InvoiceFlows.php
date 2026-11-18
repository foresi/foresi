<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Integration\CashFlow;

use Foresi\CashFlow\Contract\ActualFlow;
use Foresi\CashFlow\Contract\ActualFlowSource;
use Foresi\CashFlow\Contract\CashFlowSource;
use Foresi\CashFlow\Contract\ExpectedFlow;
use Foresi\CashFlow\Contract\FlowCategory;
use Foresi\CashFlow\Contract\FlowCertainty;
use Foresi\CashFlow\Contract\FlowDirection;
use Foresi\CashFlow\Contract\FlowSubject;
use Foresi\Invoicing\Application\InvoiceFilter;
use Foresi\Invoicing\Application\Query\InvoiceList;
use Foresi\Invoicing\Application\Query\InvoiceListItem;
use Foresi\Invoicing\Application\Query\InvoiceSliceFilter;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Infrastructure\Api\InvoiceApiController;
use Foresi\Shared\Domain\Money;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The issued invoices and advances of a firm in the forecast (C2): what is
 * still to pay after corrections and payments, on the due date. A final
 * invoice asks only what its deducted advances left, so nothing is counted
 * twice. What is to refund to a customer goes out. Corrective and payment tax
 * documents are no flows of their own: a correction changes what its invoice
 * leaves to pay. As actuals, every invoice and advance issued counts, paid or
 * not, by its issue date and customer (C13).
 */
final readonly class InvoiceFlows implements CashFlowSource, ActualFlowSource
{
    public const string KEY = 'invoicing.invoices';
    private const int CHUNK = 200;
    private const array KINDS = [DocumentKind::Invoice, DocumentKind::Advance];

    public function __construct(
        private InvoiceList $invoices,
        private InvoiceReadAccess $access,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function mayReadDetails(): bool
    {
        return $this->access->mayRead();
    }

    public function flows(string $firmId, \DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $today): iterable
    {
        $last = $to->format('Y-m-d');
        foreach ($this->items(new InvoiceSliceFilter(firmId: $firmId, state: InvoiceFilter::UNPAID)) as $item) {
            $row = $item->row;
            $remaining = $row->remaining();
            if (!\in_array($row->kind, self::KINDS, true) || $remaining->isZero() || $row->dueDate->format('Y-m-d') > $last) {
                continue;
            }
            yield new ExpectedFlow(
                self::KEY,
                $this->subject($row->id),
                $remaining->isNegative() ? FlowDirection::Out : FlowDirection::In,
                $remaining->isNegative() ? Money::ofMinor(-$remaining->minor(), $remaining->currency()) : $remaining,
                new \DateTimeImmutable($row->dueDate->format('Y-m-d'), $today->getTimezone()),
                FlowCategory::Revenue,
                FlowCertainty::Document,
                \sprintf('%s %s', $row->number, $row->customerName),
                counterpartyId: $item->customerId,
            );
        }
    }

    public function actuals(string $firmId, \DateTimeImmutable $from, \DateTimeImmutable $to): iterable
    {
        foreach ($this->items(new InvoiceSliceFilter(firmId: $firmId, issuedFrom: $from, issuedTo: $to)) as $item) {
            $row = $item->row;
            if (!\in_array($row->kind, self::KINDS, true) || $row->payable->isZero()) {
                continue;
            }
            $payable = $row->payable;
            yield new ActualFlow(
                self::KEY,
                $this->subject($row->id),
                $payable->isNegative() ? FlowDirection::Out : FlowDirection::In,
                $payable->isNegative() ? Money::ofMinor(-$payable->minor(), $payable->currency()) : $payable,
                new \DateTimeImmutable($row->issueDate->format('Y-m-d'), $from->getTimezone()),
                counterpartyId: $item->customerId,
            );
        }
    }

    /**
     * @return iterable<InvoiceListItem>
     */
    private function items(InvoiceSliceFilter $filter): iterable
    {
        $after = null;
        do {
            $slice = $this->invoices->slice($filter, $after, self::CHUNK);
            yield from $slice->items;
            $after = $slice->nextAfterId();
        } while (null !== $after);
    }

    private function subject(string $id): FlowSubject
    {
        return new FlowSubject('invoice', $id, $this->urls->generate(InvoiceApiController::SHOW, ['id' => $id], UrlGeneratorInterface::ABSOLUTE_PATH));
    }
}
