<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application;

use Foresi\Invoicing\Contract\VatRegime;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Invoicing\Domain\RecurringInvoice;
use Foresi\Party\Application\FirmBankAccounts;
use Foresi\Party\Application\InstanceFirm;
use Foresi\Party\Application\PartyQuery;
use Foresi\Party\Domain\Iban;
use Foresi\Party\Domain\InvalidIban;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PaymentMethod;

/**
 * What a recurring template must be to be saved. It meets the firm and line checks
 * of an invoice, on the tax point of its next occurrence, so a template that saves
 * issues unless the world moves meanwhile; what moves later is the run's to report.
 * It reads and never writes.
 */
final readonly class RecurringRules
{
    public function __construct(
        private LineRules $shared,
        private InvoicingCountries $countries,
        private PartyQuery $parties,
        private SeriesCatalog $catalog,
        private FirmInvoicingSettings $settings,
        private FirmBankAccounts $accounts,
        private InstanceFirm $instanceFirm,
    ) {
    }

    /**
     * @param RecurringInvoice|null $existing the template being edited
     * @param \DateTimeImmutable    $today    the instance's today
     * @param RecurringHistory|null $history  what an imported template issued elsewhere: its first
     *                                        issue date may have passed, and it may have ended there
     *
     * @return list<IssueProblem> empty when the template may be saved
     */
    public function check(RecurringInput $input, ?RecurringInvoice $existing, \DateTimeImmutable $today, ?RecurringHistory $history = null): array
    {
        $firm = $this->instanceFirm->get();
        $problems = [
            // Every occurrence is a new document, so the firm must be able to issue one.
            ...$this->shared->firm(),
            ...$this->document($input),
            ...$this->series($input),
            ...$this->customer($input, $customer),
            ...$this->shared->unprintable([
                'orderNumber' => $input->orderNumber,
                'description' => $input->description,
                'textBefore' => $input->textBefore,
                'textAfter' => $input->textAfter,
                ...$this->shared->lineTexts($input->lines),
            ]),
        ];
        [$scheduleProblems, $next] = $this->schedule($input, $existing, $today, $history);
        $problems = [...$problems, ...$scheduleProblems];
        try {
            $country = $this->countries->for($firm->address()->countryCode);
        } catch (UnsupportedCountry) {
            return [...$problems, new IssueProblem('firm', 'invoice.problem.firm_country_unsupported')];
        }
        // An ended import issues nothing more; its lines are checked on its last issue.
        $taxPoint = $next instanceof \DateTimeImmutable ? $input->taxPoint->for($next) : ($history instanceof RecurringHistory ? $input->taxPoint->for($history->lastOccurrence ?? $today) : null);
        $regime = VatRegime::of($firm->vatStatus());
        $problems = [...$problems, ...$this->shared->lines($input->lines, $regime, $country, $customer?->vatId()?->toString(), $firm->address()->countryCode, $taxPoint)];
        if ([] !== $problems || !$taxPoint instanceof \DateTimeImmutable) {
            return $problems;
        }

        return $this->shared->amounts($input->lines, $regime, $this->settings->get()->rounding(), $country, $taxPoint);
    }

    /**
     * @return list<IssueProblem>
     */
    private function document(RecurringInput $input): array
    {
        $problems = [];
        // Variables resolve to fewer characters than they take, so the typed length is the most it gets.
        if (mb_strlen($input->orderNumber) > Invoice::MAX_ORDER_NUMBER_LENGTH) {
            $problems[] = new IssueProblem('orderNumber', 'invoice.problem.too_long');
        }
        if (mb_strlen($input->constantSymbol) > Invoice::MAX_CONSTANT_SYMBOL_LENGTH) {
            $problems[] = new IssueProblem('constantSymbol', 'invoice.problem.too_long');
        } elseif ('' !== $input->constantSymbol && !ctype_digit($input->constantSymbol)) {
            $problems[] = new IssueProblem('constantSymbol', 'invoice.problem.constant_symbol_invalid');
        }
        if (PaymentMethod::BankTransfer === $input->paymentMethod && !$this->isAccountOf($input->bankAccount)) {
            $problems[] = new IssueProblem('bankAccount', 'invoice.problem.bank_account_foreign');
        }

        return $problems;
    }

    private function isAccountOf(?string $account): bool
    {
        if (null === $account) {
            return false;
        }
        try {
            return \in_array(Iban::fromString($account)->toString(), $this->accounts->ibans(), true);
        } catch (InvalidIban) {
            return false;
        }
    }

    /**
     * @return list<IssueProblem>
     */
    private function series(RecurringInput $input): array
    {
        if (null === $input->seriesId) {
            return [];
        }
        try {
            $series = $this->catalog->get($input->seriesId);
        } catch (UnknownSeries) {
            return [new IssueProblem('series', 'invoice.problem.series_unknown')];
        }
        // A template issues invoices or advance invoices, as iDoklad's does; never a document that follows another.
        if (!$series->kind()->standsAlone()) {
            return [new IssueProblem('series', 'invoice.problem.series_kind')];
        }

        return $series->isArchived() ? [new IssueProblem('series', 'invoice.problem.series_archived')] : [];
    }

    /**
     * @param-out Party|null $customer
     *
     * @return list<IssueProblem>
     */
    private function customer(RecurringInput $input, ?Party &$customer): array
    {
        $customer = $this->parties->find($input->customerId);
        if (!$customer instanceof Party) {
            return [new IssueProblem('customer', 'invoice.problem.customer_unknown')];
        }

        return $customer->isArchived() ? [new IssueProblem('customer', 'invoice.problem.customer_archived')] : [];
    }

    /**
     * @return array{list<IssueProblem>, \DateTimeImmutable|null} the problems and the next date the template would have
     */
    private function schedule(RecurringInput $input, ?RecurringInvoice $existing, \DateTimeImmutable $today, ?RecurringHistory $history): array
    {
        $problems = [];
        $first = $input->recurrence->firstIssueOn()->format('Y-m-d');
        // An edit keeps a first date that has passed, and so does an import; only a new one must lie ahead.
        $changed = !$existing instanceof RecurringInvoice || $first !== $existing->recurrence()->firstIssueOn()->format('Y-m-d');
        if ($changed && !$history instanceof RecurringHistory && $first < $today->format('Y-m-d')) {
            $problems[] = new IssueProblem('recurrence.firstIssueOn', 'recurring.problem.first_issue_past');
        }
        if ($input->dueDays < 0 || $input->dueDays > RecurringInvoice::MAX_DUE_DAYS) {
            $problems[] = new IssueProblem('dueDays', 'recurring.problem.due_days_invalid');
        }
        if ($history instanceof RecurringHistory) {
            return [$problems, RecurringInvoice::occurrenceAfter($input->recurrence, $history->issuedCount, $history->lastOccurrence, $history->restartedOn)];
        }
        $next = $existing instanceof RecurringInvoice
            ? $existing->nextOccurrenceUnder($input->recurrence, $today)
            : RecurringInvoice::firstOccurrenceFrom($input->recurrence, 0, $input->recurrence->firstIssueOn());
        if (!$next instanceof \DateTimeImmutable) {
            $problems[] = new IssueProblem('recurrence', 'recurring.problem.no_occurrence_left');
        }

        return [$problems, $next];
    }
}
