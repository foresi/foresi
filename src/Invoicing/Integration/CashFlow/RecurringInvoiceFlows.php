<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Integration\CashFlow;

use Foresi\CashFlow\Contract\CashFlowSource;
use Foresi\CashFlow\Contract\ExpectedFlow;
use Foresi\CashFlow\Contract\FlowCategory;
use Foresi\CashFlow\Contract\FlowCertainty;
use Foresi\CashFlow\Contract\FlowDirection;
use Foresi\CashFlow\Contract\FlowSubject;
use Foresi\Invoicing\Application\InvoicingAccess;
use Foresi\Invoicing\Application\RecurringAmounts;
use Foresi\Invoicing\Application\RecurringFilter;
use Foresi\Invoicing\Application\RecurringInvoices;
use Foresi\Invoicing\Application\RecurringOverview;
use Foresi\Invoicing\Domain\RecurringInvoice;
use Foresi\Invoicing\Domain\RecurringState;
use Foresi\Shared\Domain\Money;
use Foresi\Shared\Domain\Schedule\RecurringSchedule;

/**
 * The invoices active recurring templates will issue (C2): each next date up
 * to the end of the forecast, due its due days later, for what the template's
 * next invoice comes to. Paused and ended templates issue nothing. An issued
 * occurrence is an invoice and counted by InvoiceFlows; the template's next
 * date has moved past it then.
 */
final readonly class RecurringInvoiceFlows implements CashFlowSource
{
    public const string KEY = 'invoicing.recurring_invoices';
    private const int CHUNK = 64;

    public function __construct(
        private InvoicingAccess $access,
        private RecurringInvoices $templates,
        private RecurringOverview $overview,
        private RecurringAmounts $amounts,
        private InvoiceReadAccess $readAccess,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function mayReadDetails(): bool
    {
        return $this->readAccess->mayRead();
    }

    public function flows(string $firmId, \DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $today): iterable
    {
        $this->access->denyUnlessStaff();
        $templates = $this->active($firmId);
        if ([] === $templates) {
            return;
        }
        $amounts = $this->amounts->payable($templates, $this->overview->prepare($templates), $today);
        $zone = $today->getTimezone();
        $last = $to->format('Y-m-d');
        foreach ($templates as $template) {
            $amount = $amounts[$template->id()] ?? null;
            $next = $template->nextOccurrence();
            if (!$amount instanceof Money || $amount->isZero() || !$next instanceof \DateTimeImmutable) {
                continue;
            }
            $recurrence = $template->recurrence();
            $issued = $template->issuedCount();
            $cursor = new \DateTimeImmutable($next->format('Y-m-d'), $recurrence->firstIssueOn()->getTimezone());
            do {
                $days = RecurringSchedule::next($recurrence, $issued, $cursor, self::CHUNK);
                foreach ($days as $day) {
                    $due = new \DateTimeImmutable($day->format('Y-m-d'), $zone)->modify(\sprintf('+%d days', $template->dueDays()));
                    if ($due->format('Y-m-d') > $last) {
                        continue 3;
                    }
                    yield $this->flow($template, $amount, $day, $due);
                    ++$issued;
                    $cursor = $day->modify('+1 day');
                }
            } while (self::CHUNK === \count($days));
        }
    }

    /**
     * @return list<RecurringInvoice>
     */
    private function active(string $firmId): array
    {
        $templates = [];
        $page = 1;
        do {
            $found = $this->templates->page(new RecurringFilter($firmId, RecurringState::Active, false, $page));
            array_push($templates, ...$found->templates);
            ++$page;
        } while ($found->page < $found->pages);

        return $templates;
    }

    private function flow(RecurringInvoice $template, Money $amount, \DateTimeImmutable $occurrence, \DateTimeImmutable $due): ExpectedFlow
    {
        return new ExpectedFlow(
            self::KEY,
            new FlowSubject('recurring_invoice', $template->id()),
            $amount->isNegative() ? FlowDirection::Out : FlowDirection::In,
            $amount->isNegative() ? Money::ofMinor(-$amount->minor(), $amount->currency()) : $amount,
            $due,
            FlowCategory::Revenue,
            FlowCertainty::Schedule,
            \sprintf('%s (%s)', $template->name(), $occurrence->format('Y-m-d')),
            counterpartyId: $template->customer()->id(),
        );
    }
}
