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
use App\Party\Application\PartyQuery;
use App\Party\Application\TransactionalWork;

/**
 * Saving an issued invoice. Firm, regime, rounding and the copy of the issuer stay
 * as they were at issue; the rest is recalculated from the input. Series and number
 * change only when the input asks for it, and only until delivery. Lines change in
 * place by position, so a line saved unchanged writes no audit entry.
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
     * A failure detaches the invoice given here; load it again to go on.
     *
     * @throws IssueRefused when a rule refuses; nothing changes
     */
    public function edit(Invoice $invoice, InvoiceInput $input): void
    {
        $id = $invoice->id();
        $this->retry->run(function () use ($id, $input): void {
            $invoice = $this->store->get($id);
            $firm = $invoice->firm();
            $problems = $this->rules->check($input, $firm, $invoice);
            if ([] !== $problems) {
                throw new IssueRefused($problems);
            }
            $party = $this->parties->find($input->customerId) ?? throw new \LogicException('IssueRules refuses an unknown customer.');
            $customer = $this->copies->for($input, $party, $firm, $invoice) ?? throw new \LogicException('IssueRules refuses a customer without an address.');
            $series = null === $input->seriesId ? $invoice->series() : $this->catalog->get($input->seriesId);
            $change = $input->change($party, $customer);

            $this->work->run(function () use ($invoice, $input, $series, $change): void {
                $this->renumber($invoice, $input, $series);
                $this->save($invoice, $input, $change);
            });
        });
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
        $invoice->change($change);
        $country = $this->countries->for($invoice->issuer()->address->countryCode);
        $regime = $invoice->vatRegime();
        $lines = $this->pricing->priced($input->lines, $regime, $country, $change->taxPointDate);
        $stored = $this->store->lines($invoice);
        foreach ($lines as $position => $line) {
            $typed = $line->input;
            if (isset($stored[$position])) {
                $stored[$position]->revise($position, $typed->text, $typed->unit, $typed->rateKind, $typed->supplyCode, $line->values, $line->amounts);
            } else {
                $this->store->addLine(new InvoiceLine($invoice, $position, $typed->text, $typed->unit, $typed->rateKind, $typed->supplyCode, $line->values, $line->amounts));
            }
        }
        foreach (\array_slice($stored, \count($lines)) as $line) {
            $this->store->removeLine($line);
        }
        $invoice->assignTotals($this->pricing->totals($lines, $regime, $invoice->rounding(), $invoice->currency()));
    }
}
