<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\InvoiceLine;
use App\Invoicing\Domain\Quantity;

/**
 * What the form of a corrective tax document opens with: the invoice it corrects
 * with every line negated, a credit note of all of it to edit down. Firm, customer,
 * payment, texts and the rates and treatments of the lines stay; the default series
 * of corrections numbers it. It writes nothing: only Save issues.
 */
final readonly class CorrectInvoice
{
    public function __construct(private NewInvoiceDefaults $defaults, private InvoiceStore $store)
    {
    }

    /**
     * Whether a corrective tax document can be issued for a document.
     */
    public static function corrects(Invoice $source): bool
    {
        return DocumentKind::Invoice === $source->kind() && !$source->isTrashed();
    }

    /**
     * @throws \DomainException   for a document that is no issued invoice in place
     * @throws UnsupportedCountry for a firm whose country has no invoicing rules
     */
    public function valuesFrom(Invoice $source): InvoiceDraftValues
    {
        if (!self::corrects($source)) {
            throw new \DomainException('Only an invoice that is not in the trash is corrected.');
        }
        $draft = $this->defaults->for($source->firm(), kind: DocumentKind::CorrectionDocument);

        return $draft->with([
            'customerId' => $source->customerParty()->id(),
            'paymentMethod' => $source->paymentMethod(),
            'bankAccount' => $source->bankAccount() ?? $draft->bankAccount,
            'description' => $source->description(),
            'language' => $source->language(),
            'lines' => array_values(array_map($this->negated(...), array_filter($this->store->lines($source), static fn (InvoiceLine $line): bool => null === $line->deducts()))),
            'correctedInvoiceId' => $source->id(),
        ]);
    }

    private function negated(InvoiceLine $line): LineInput
    {
        $quantity = Quantity::ofTenThousandths(-$line->quantity()->tenThousandths());

        return new LineInput($line->text(), $quantity, $line->unit(), $line->price(), $line->priceType(), $line->treatment(), $line->rateKind(), $line->supplyCode(), $line->discount());
    }
}
