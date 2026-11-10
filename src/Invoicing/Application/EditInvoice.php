<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\InvoiceChange;
use App\Invoicing\Domain\InvoiceLine;
use App\Invoicing\Domain\Series;
use App\Invoicing\Domain\VariableSymbol;
use App\Party\Application\ContactSettingsForFirm;
use App\Party\Application\PartyQuery;
use App\Party\Application\TransactionalWork;
use Psr\Clock\ClockInterface;

/**
 * Saving an issued invoice. Firm, regime, rounding and the copy of the issuer stay
 * as they were at issue; the rest is recalculated from the input. Series and number
 * change only when the input asks for it, and only until delivery. Lines change in
 * place by position, so a line saved unchanged writes no audit entry. The form keeps
 * with each row the id of the line it was loaded from, so the work a row billed, such
 * as time, follows the row when it moves, and is free again when the row is removed.
 * The whole save runs under the lock of the invoice, rules included, so what it checks
 * stays true.
 */
final readonly class EditInvoice
{
    public function __construct(
        private InvoiceStore $store,
        private SeriesCatalog $catalog,
        private PartyQuery $parties,
        private IssueRules $rules,
        private CustomerCopies $copies,
        private LinePricing $pricing,
        private InvoicingCountries $countries,
        private NumberAllocator $numbers,
        private TransactionalWork $work,
        private ConflictRetry $retry,
        private ContactSettingsForFirm $contacts,
        private AdvanceDeductions $deductions,
        private CorrectionRules $corrections,
        private ClockInterface $clock,
        private BilledWorkRelease $billed,
    ) {
    }

    /**
     * @param int $version the version of the invoice when the form was opened
     *
     * @return Invoice the invoice as saved; anything loaded before is detached
     *
     * @throws IssueRefused   when a rule refuses, or when someone saved since the form
     *                        was opened (field "version"); nothing changes
     * @throws UnknownInvoice also for an invoice of another tenant
     */
    public function edit(string $id, InvoiceInput $input, int $version): Invoice
    {
        return $this->retry->run(fn (): Invoice => $this->work->run(function () use ($id, $input, $version): Invoice {
            $invoice = $this->store->lock($id);
            if ($invoice->version() !== $version) {
                throw new IssueRefused([new IssueProblem('version', 'invoice.problem.changed_meanwhile')]);
            }
            $firm = $invoice->firm();
            $problems = $this->rules->check($input, $firm, $invoice);
            if ([] !== $problems) {
                throw new IssueRefused($problems);
            }
            $party = $this->parties->find($input->customerId) ?? throw new \LogicException('IssueRules refuses an unknown customer.');
            $customer = $this->copies->for($input, $party, $firm, $invoice) ?? throw new \LogicException('IssueRules refuses a customer without an address.');
            $series = null === $input->seriesId ? $invoice->series() : $this->catalog->get($input->seriesId);
            // An archived firm has no settings to change; its old documents can still be edited.
            if ($party->id() !== $invoice->customerParty()->id() && !$firm->isArchived()) {
                $this->contacts->makeCustomer($party, $firm);
            }
            $this->renumber($invoice, $input, $series);
            $this->save($invoice, $input, $input->change($party, $customer, $invoice->kind()));

            return $invoice;
        }));
    }

    private function renumber(Invoice $invoice, InvoiceInput $input, Series $series): void
    {
        if (!$invoice->isRenumberedBy($input->seriesId, $input->typedNumber)) {
            return;
        }
        $number = $this->numbers->allocate($series, $invoice->firm(), $input->issueDate, $input->typedNumber, $invoice);
        $abandoned = $invoice->changeNumber($series, $number);
        if ($abandoned instanceof \App\Invoicing\Domain\AbandonedNumber) {
            $this->store->addAbandoned($abandoned);
        }
    }

    private function save(Invoice $invoice, InvoiceInput $input, InvoiceChange $change): void
    {
        $invoice->change($input->variableSymbolFollowsNumber ? $change->withVariableSymbol(VariableSymbol::fromNumber($invoice->number())) : $change, \DateTimeImmutable::createFromInterface($this->clock->now()));
        $country = $this->countries->for($invoice->issuer()->address->countryCode);
        $regime = $invoice->vatRegime();
        $lines = $this->pricing->priced($input->lines, $regime, $country, $this->corrections->rateDate($input, $invoice) ?? $change->rateDate());
        if ($input->correction instanceof CorrectionInput) {
            CorrectionRules::record($invoice, $input->correction);
            $this->corrections->claim($input, $invoice, $regime, $country);
        }
        $stored = [];
        foreach ($this->store->lines($invoice) as $line) {
            $stored[$line->position()] = $line;
        }
        $origins = $this->origins($stored, array_map(static fn (LineInput $line): ?string => $line->source, $input->lines));
        $moved = array_fill_keys(array_map(static fn (InvoiceLine $line): string => $line->id(), $stored), null);
        foreach ($lines as $position => $line) {
            $typed = $line->input;
            if (isset($stored[$position])) {
                $saved = $stored[$position];
                $saved->revise($typed->text, $typed->unit, $typed->rateKind, $typed->supplyCode, $line->values, $line->amounts, $typed->deducts);
            } else {
                $saved = new InvoiceLine($invoice, $position, $typed->text, $typed->unit, $typed->rateKind, $typed->supplyCode, $line->values, $line->amounts, $typed->deducts);
                $this->store->addLine($saved);
            }
            if (isset($origins[$position])) {
                $moved[$origins[$position]] = $saved->id();
            }
        }
        foreach ($stored as $position => $line) {
            if ($position >= \count($lines)) {
                $this->store->removeLine($line);
            }
        }
        $moved = array_filter($moved, static fn (?string $now, string $was): bool => $now !== $was, \ARRAY_FILTER_USE_BOTH);
        if ([] !== $moved) {
            $this->billed->revised($invoice, $moved);
        }
        $invoice->assignTotals($this->pricing->totals($lines, $regime, $invoice->rounding(), $country));
        $this->deductions->claim($invoice, AdvanceDeductions::deducted($input->lines));
    }

    /**
     * The id of the stored line each row was loaded from. A row names it by its
     * source; the first row naming a line is that line, and a copy of it is new.
     * Input whose rows name no stored line at all, as from a caller other than
     * the form, is by position: each row is the line it lands on.
     *
     * @param array<int, InvoiceLine> $stored  by position
     * @param array<int, ?string>     $sources the source of each row, by position
     *
     * @return array<int, string> by the position of the row
     */
    private function origins(array $stored, array $sources): array
    {
        $ids = array_map(static fn (InvoiceLine $line): string => $line->id(), $stored);
        $known = array_flip($ids);
        $origins = [];
        foreach ($sources as $position => $source) {
            if (null !== $source && isset($known[$source]) && !\in_array($source, $origins, true)) {
                $origins[$position] = $source;
            }
        }
        if ([] !== $origins) {
            return $origins;
        }

        return array_intersect_key($ids, $sources);
    }
}
