<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Integration\Vat;

use Foresi\Identity\Application\ApiTokenContext;
use Foresi\Identity\Application\ApiTokenGrant;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Invoicing\Application\RecurringAmounts;
use Foresi\Invoicing\Application\RecurringFilter;
use Foresi\Invoicing\Application\RecurringInvoices;
use Foresi\Invoicing\Application\RecurringOverview;
use Foresi\Invoicing\Domain\RecurringInvoice;
use Foresi\Invoicing\Domain\RecurringState;
use Foresi\Shared\Domain\Money;
use Foresi\Shared\Domain\Schedule\RecurringSchedule;
use Foresi\Vat\Contract\ScheduledVat;
use Foresi\Vat\Contract\VatScheduleSource;
use Foresi\Vat\Contract\VatSubject;

/**
 * The output VAT the invoices of active recurring templates will give: the
 * same occurrences the cash-flow forecast counts, from the template's next
 * date on, each on the tax point its invoice would get when issued that day,
 * with the VAT of the template's lines as an issue prices them, in the
 * template's currency. Paused and ended templates give nothing. An issued
 * occurrence is an invoice and counts through IssuedDocumentVat; the
 * template's next date has moved past it then, so it never counts twice.
 */
final readonly class RecurringInvoiceVat implements VatScheduleSource
{
    public const string KEY = 'invoicing.recurring_vat';
    private const int CHUNK = 64;

    public function __construct(
        private RecurringInvoices $templates,
        private RecurringOverview $overview,
        private RecurringAmounts $amounts,
        private ApiTokenContext $tokens,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function mayReadDetails(): bool
    {
        $grant = $this->tokens->grant();

        return !$grant instanceof ApiTokenGrant || $grant->allows(ApiScope::of('invoices', false));
    }

    public function between(\DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $today): iterable
    {
        $templates = $this->active();
        if ([] === $templates) {
            return;
        }
        $vats = $this->amounts->vat($templates, $this->overview->prepare($templates), $today);
        $zone = $from->getTimezone();
        $first = $from->format('Y-m-d');
        $last = $to->format('Y-m-d');
        foreach ($templates as $template) {
            $next = $template->nextOccurrence();
            $vat = $vats[$template->id()] ?? null;
            if (!$vat instanceof Money || $vat->isZero() || !$next instanceof \DateTimeImmutable) {
                continue;
            }
            $recurrence = $template->recurrence();
            $issued = $template->issuedCount();
            $cursor = new \DateTimeImmutable($next->format('Y-m-d'), $recurrence->firstIssueOn()->getTimezone());
            do {
                $days = RecurringSchedule::next($recurrence, $issued, $cursor, self::CHUNK);
                foreach ($days as $day) {
                    $taxPoint = new \DateTimeImmutable($template->taxPoint()->for($day)->format('Y-m-d'), $zone);
                    if ($taxPoint->format('Y-m-d') > $last) {
                        continue 3;
                    }
                    if ($taxPoint->format('Y-m-d') >= $first) {
                        yield $this->vat($template, $day, $taxPoint, $vat);
                    }
                    ++$issued;
                    $cursor = $day->modify('+1 day');
                }
            } while (self::CHUNK === \count($days));
        }
    }

    /**
     * @return list<RecurringInvoice>
     */
    private function active(): array
    {
        $templates = [];
        $page = 1;
        do {
            $found = $this->templates->page(new RecurringFilter(RecurringState::Active, false, $page));
            array_push($templates, ...$found->templates);
            ++$page;
        } while ($found->page < $found->pages);

        return $templates;
    }

    private function vat(RecurringInvoice $template, \DateTimeImmutable $occurrence, \DateTimeImmutable $taxPoint, Money $vat): ScheduledVat
    {
        return new ScheduledVat(
            self::KEY,
            new VatSubject('recurring_invoice', $template->id(), null),
            \sprintf('%s (%s)', $template->name(), $occurrence->format('Y-m-d')),
            $taxPoint,
            $vat,
        );
    }
}
