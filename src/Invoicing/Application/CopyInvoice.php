<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application;

use Foresi\Invoicing\Contract\PriceType;
use Foresi\Invoicing\Contract\TaxTreatment;
use Foresi\Invoicing\Contract\VatRateKind;
use Foresi\Invoicing\Contract\VatRegime;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Invoicing\Domain\InvoiceLine;
use Foresi\Invoicing\Domain\Series;
use Foresi\Invoicing\Domain\VariableSymbol;
use Foresi\Party\Application\ContactSettingsForFirm;
use Foresi\Party\Application\FirmBankAccounts;
use Foresi\Party\Domain\InvoicingDefaults;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\Party;

/**
 * What the form opens with for a copy. It writes nothing: only Save issues, as a new
 * document with a new number and new snapshots. Number, dates and order number start
 * over; firm, series, customer, description, lines, texts, language and payment stay.
 * Rates come from the kind on the new tax point. What can no longer be used does not
 * stop the form: it says so, and a save needs another choice. Lines follow the VAT
 * status the firm has now, as the document will.
 */
final readonly class CopyInvoice
{
    public function __construct(
        private NewInvoiceDefaults $defaults,
        private InvoiceStore $store,
        private NumberCounter $counter,
        private ContactSettingsForFirm $contacts,
        private FirmBankAccounts $accounts,
    ) {
    }

    /**
     * @throws UnsupportedCountry for a firm whose country has no invoicing rules
     */
    public function valuesFrom(Invoice $source): InvoiceDraftValues
    {
        $firm = $source->firm();
        // A copy is of the same kind: an advance invoice copies to an advance invoice.
        $draft = $this->defaults->for($firm, kind: $source->kind());
        $notices = $firm->isArchived() ? ['invoice.copy.firm_archived'] : [];

        $series = $source->series();
        if ($series->isArchived()) {
            $notices[] = 'invoice.copy.series_archived';
        } else {
            $draft = $this->inSeries($draft, $series);
        }

        $party = $source->customerParty();
        $customer = !$this->contacts->isArchivedFor($party, $firm);
        if (!$customer) {
            $notices[] = 'invoice.copy.customer_archived';
        }

        return $draft->with([
            'customerId' => $customer ? $party->id() : null,
            'dueDate' => $this->dueDate($draft, $customer, $party, $firm),
            'paymentMethod' => $source->paymentMethod(),
            'bankAccount' => \in_array($source->bankAccount(), $this->accounts->ibansOf($firm), true) ? $source->bankAccount() : $draft->bankAccount,
            'constantSymbol' => $source->constantSymbol(),
            'description' => $source->description(),
            'textBefore' => $source->textBefore(),
            'textAfter' => $source->textAfter(),
            'language' => $source->language(),
            'lines' => array_map(fn (InvoiceLine $line): LineInput => $this->line($line, $draft->regime), $this->store->lines($source)),
            'notices' => $notices,
        ]);
    }

    private function inSeries(InvoiceDraftValues $draft, Series $series): InvoiceDraftValues
    {
        $number = $this->counter->peek($series, $draft->issueDate);

        return $draft->with(['seriesId' => $series->id(), 'number' => $number, 'variableSymbol' => VariableSymbol::fromNumber($number)]);
    }

    private function dueDate(InvoiceDraftValues $draft, bool $customer, Party $party, LegalEntity $firm): \DateTimeImmutable
    {
        $days = $customer ? $this->contacts->defaultsFor($party, $firm)->dueDays : InvoicingDefaults::DEFAULT_DUE_DAYS;

        return $draft->issueDate->modify(\sprintf('+%d days', $days));
    }

    private function line(InvoiceLine $line, VatRegime $regime): LineInput
    {
        [$priceType, $treatment, $kind, $code] = match (true) {
            VatRegime::Payer !== $regime => [PriceType::WithoutVat, TaxTreatment::NotApplicable, null, null],
            TaxTreatment::NotApplicable === $line->treatment() => [$line->priceType(), TaxTreatment::Standard, VatRateKind::Base, null],
            default => [$line->priceType(), $line->treatment(), $line->rateKind(), $line->supplyCode()],
        };

        return new LineInput($line->text(), $line->quantity(), $line->unit(), $line->price(), $priceType, $treatment, $kind, $code, $line->discount());
    }
}
