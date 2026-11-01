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
use App\Party\Application\PartyQuery;
use App\Party\Application\TransactionalWork;

/**
 * Saving an issued invoice. Firm, regime, rounding and the copy of the issuer stay
 * as they were at issue; the rest is recalculated from the input. Series and number
 * change only when the input asks for it, and only until delivery. Lines change in
 * place by position, so a line saved unchanged writes no audit entry. The whole save
 * runs under the lock of the invoice, rules included, so what it checks stays true.
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
            $this->renumber($invoice, $input, $series);
            $this->save($invoice, $input, $input->change($party, $customer));

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
        $invoice->change($input->variableSymbolFollowsNumber ? $change->withVariableSymbol(VariableSymbol::fromNumber($invoice->number())) : $change);
        $country = $this->countries->for($invoice->issuer()->address->countryCode);
        $regime = $invoice->vatRegime();
        $lines = $this->pricing->priced($input->lines, $regime, $country, $change->taxPointDate);
        $stored = [];
        foreach ($this->store->lines($invoice) as $line) {
            $stored[$line->position()] = $line;
        }
        foreach ($lines as $position => $line) {
            $typed = $line->input;
            if (isset($stored[$position])) {
                $stored[$position]->revise($typed->text, $typed->unit, $typed->rateKind, $typed->supplyCode, $line->values, $line->amounts);
            } else {
                $this->store->addLine(new InvoiceLine($invoice, $position, $typed->text, $typed->unit, $typed->rateKind, $typed->supplyCode, $line->values, $line->amounts));
            }
        }
        foreach ($stored as $position => $line) {
            if ($position >= \count($lines)) {
                $this->store->removeLine($line);
            }
        }
        $invoice->assignTotals($this->pricing->totals($lines, $regime, $invoice->rounding(), $country));
    }
}
