<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\RecurringInvoice;
use App\Invoicing\Domain\VatRegime;
use App\Party\Application\ContactSettingsForFirm;
use App\Party\Application\FirmBankAccounts;
use App\Party\Application\PartyQuery;
use App\Party\Domain\Iban;
use App\Party\Domain\InvalidIban;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Party\Domain\PaymentMethod;

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
        private ContactSettingsForFirm $contacts,
        private SeriesCatalog $catalog,
        private FirmInvoicingSettings $settings,
        private FirmBankAccounts $accounts,
    ) {
    }

    /**
     * @param RecurringInvoice|null $existing the template being edited
     * @param \DateTimeImmutable    $today    the tenant's today
     *
     * @return list<IssueProblem> empty when the template may be saved
     */
    public function check(RecurringInput $input, LegalEntity $firm, ?RecurringInvoice $existing, \DateTimeImmutable $today): array
    {
        $problems = [
            ...$this->firm($input, $firm, $existing),
            ...$this->document($input, $firm),
            ...$this->series($input, $firm),
            ...$this->customer($input, $firm, $customer),
            ...$this->shared->unprintable([
                'orderNumber' => $input->orderNumber,
                'description' => $input->description,
                'textBefore' => $input->textBefore,
                'textAfter' => $input->textAfter,
                ...$this->shared->lineTexts($input->lines),
            ]),
        ];
        [$scheduleProblems, $next] = $this->schedule($input, $existing, $today);
        $problems = [...$problems, ...$scheduleProblems];
        try {
            $country = $this->countries->for($firm->address()->countryCode);
        } catch (UnsupportedCountry) {
            return [...$problems, new IssueProblem('firm', 'invoice.problem.firm_country_unsupported')];
        }
        $taxPoint = $next instanceof \DateTimeImmutable ? $input->taxPoint->for($next) : null;
        $regime = VatRegime::of($firm->vatStatus());
        $problems = [...$problems, ...$this->shared->lines($input->lines, $regime, $country, $customer?->vatId()?->toString(), $firm->address()->countryCode, $taxPoint)];
        if ([] !== $problems || !$taxPoint instanceof \DateTimeImmutable) {
            return $problems;
        }

        return $this->shared->amounts($input->lines, $regime, $this->settings->for($firm)->rounding(), $country, $taxPoint);
    }

    /**
     * @return list<IssueProblem>
     */
    private function firm(RecurringInput $input, LegalEntity $firm, ?RecurringInvoice $existing): array
    {
        if ($existing instanceof RecurringInvoice && $input->firmId !== $existing->firm()->id()) {
            return [new IssueProblem('firm', 'invoice.problem.firm_locked')];
        }

        // Every occurrence is a new document, so the firm must be able to issue one.
        return $this->shared->firm($firm);
    }

    /**
     * @return list<IssueProblem>
     */
    private function document(RecurringInput $input, LegalEntity $firm): array
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
        if (PaymentMethod::BankTransfer === $input->paymentMethod && !$this->isAccountOf($input->bankAccount, $firm)) {
            $problems[] = new IssueProblem('bankAccount', 'invoice.problem.bank_account_foreign');
        }

        return $problems;
    }

    private function isAccountOf(?string $account, LegalEntity $firm): bool
    {
        if (null === $account) {
            return false;
        }
        try {
            return \in_array(Iban::fromString($account)->toString(), $this->accounts->ibansOf($firm), true);
        } catch (InvalidIban) {
            return false;
        }
    }

    /**
     * @return list<IssueProblem>
     */
    private function series(RecurringInput $input, LegalEntity $firm): array
    {
        if (null === $input->seriesId) {
            return [];
        }
        try {
            $series = $this->catalog->get($input->seriesId);
        } catch (UnknownSeries) {
            return [new IssueProblem('series', 'invoice.problem.series_unknown')];
        }
        if ($series->firm()->id() !== $firm->id()) {
            return [new IssueProblem('series', 'invoice.problem.series_of_another_firm')];
        }
        if (DocumentKind::Invoice !== $series->kind()) {
            return [new IssueProblem('series', 'invoice.problem.series_kind')];
        }

        return $series->isArchived() ? [new IssueProblem('series', 'invoice.problem.series_archived')] : [];
    }

    /**
     * @param-out Party|null $customer
     *
     * @return list<IssueProblem>
     */
    private function customer(RecurringInput $input, LegalEntity $firm, ?Party &$customer): array
    {
        $customer = $this->parties->find($input->customerId);
        if (!$customer instanceof Party) {
            return [new IssueProblem('customer', 'invoice.problem.customer_unknown')];
        }

        return $this->contacts->isArchivedFor($customer, $firm) ? [new IssueProblem('customer', 'invoice.problem.customer_archived')] : [];
    }

    /**
     * @return array{list<IssueProblem>, \DateTimeImmutable|null} the problems and the next date the template would have
     */
    private function schedule(RecurringInput $input, ?RecurringInvoice $existing, \DateTimeImmutable $today): array
    {
        $problems = [];
        $first = $input->recurrence->firstIssueOn()->format('Y-m-d');
        // An edit keeps a first date that has passed; only a new one must lie ahead.
        $changed = !$existing instanceof RecurringInvoice || $first !== $existing->recurrence()->firstIssueOn()->format('Y-m-d');
        if ($changed && $first < $today->format('Y-m-d')) {
            $problems[] = new IssueProblem('recurrence.firstIssueOn', 'recurring.problem.first_issue_past');
        }
        if ($input->dueDays < 0 || $input->dueDays > RecurringInvoice::MAX_DUE_DAYS) {
            $problems[] = new IssueProblem('dueDays', 'recurring.problem.due_days_invalid');
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
