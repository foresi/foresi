<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application\Reading;

use Foresi\Party\Domain\Party;
use Foresi\Purchasing\Application\EditReceivedDocument;
use Foresi\Purchasing\Application\PurchasingAccess;
use Foresi\Purchasing\Application\PurchasingNotAllowed;
use Foresi\Purchasing\Application\ReceivedDocumentInput;
use Foresi\Purchasing\Application\ReceivedDocuments;
use Foresi\Purchasing\Application\SavedReceivedDocument;
use Foresi\Purchasing\Application\UnknownReceivedDocument;
use Foresi\Purchasing\Contract\Reading\ReadField;
use Foresi\Purchasing\Contract\Reading\Reading;
use Foresi\Purchasing\Domain\DocumentReading;
use Foresi\Purchasing\Domain\ReceivedDocument;
use Foresi\Purchasing\Domain\ReceivedDocumentKind;
use Foresi\Purchasing\Domain\ReceivedDocumentRefused;
use Foresi\Purchasing\Domain\VatRate;
use Foresi\Purchasing\Domain\VatRecapLine;
use Foresi\Purchasing\Domain\VatRecapRow;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;

/**
 * A person takes values the reading of a document's file left as
 * suggestions: the chosen fields take what was read, everything else stays as
 * the document holds it. It is a save like any other (EditReceivedDocument):
 * the version the person saw is checked and the document refuses what does
 * not fit. The supplier is the contact the reading matches now.
 */
final readonly class AcceptReadValues
{
    public function __construct(
        private PurchasingAccess $access,
        private ReceivedDocuments $documents,
        private DocumentReadings $readings,
        private SupplierMatching $matching,
        private EditReceivedDocument $editing,
    ) {
    }

    /**
     * @param list<ReadField> $fields  the fields to take; a field the reading has no value for is skipped
     * @param int             $version the version of the document the person saw
     *
     * @throws PurchasingNotAllowed    for anyone but the owners and members of the organisation
     * @throws UnknownReceivedDocument
     * @throws ReceivedDocumentRefused as EditReceivedDocument does, and SUPPLIER_UNKNOWN when no contact matches the read supplier any more
     */
    public function accept(string $documentId, array $fields, int $version): SavedReceivedDocument
    {
        $this->access->denyUnlessStaff();
        $document = $this->documents->find($documentId) ?? throw new UnknownReceivedDocument();
        $reading = $this->readings->find($documentId);
        $merged = $reading instanceof DocumentReading ? $reading->reading() : new Reading([]);
        $taken = array_values(array_filter(
            array_unique($fields, \SORT_REGULAR),
            static fn (ReadField $field): bool => \in_array($field, ReadValues::FIELDS, true) && ReadValues::shaped($merged, $field) && !ReadValues::blockedAmount($merged, $field)
                || (ReadField::SupplierName === $field && $merged->get($field) instanceof \Foresi\Purchasing\Contract\Reading\ReadValue),
        ));

        return $this->editing->edit($documentId, $this->input($document, $merged, $taken), $version);
    }

    /**
     * What the document holds, with the taken fields as read.
     *
     * @param list<ReadField> $taken
     *
     * @throws ReceivedDocumentRefused with SUPPLIER_UNKNOWN or VAT_RATE
     */
    private function input(ReceivedDocument $document, Reading $merged, array $taken): ReceivedDocumentInput
    {
        $value = static fn (ReadField $field): mixed => \in_array($field, $taken, true) ? $merged->get($field)?->value : null;
        $date = static fn (ReadField $field, ?\DateTimeImmutable $current): ?\DateTimeImmutable => \in_array($field, $taken, true) ? ReadValues::date($value($field)) : $current;

        $kindValue = $value(ReadField::Kind);
        $kind = \is_string($kindValue) ? (ReceivedDocumentKind::tryFrom($kindValue) ?? $document->kind()) : $document->kind();
        $currencyValue = $value(ReadField::Currency);
        $currency = \is_string($currencyValue) ? (Currency::tryFrom($currencyValue) ?? $document->currency()) : $document->currency();
        // A credit note subtracts; the formats write its amounts as positive.
        $sign = static fn (int $minor): int => ReceivedDocumentKind::CreditNote === $kind ? -abs($minor) : $minor;
        $totalValue = $value(ReadField::Total);
        $total = \is_int($totalValue) ? Money::ofMinor($sign($totalValue), $currency) : $document->total();
        $rows = ReadValues::recap($value(ReadField::VatRecap));
        $recap = null === $rows
            ? array_map(static fn (VatRecapRow $row): VatRecapLine => new VatRecapLine($row->rate, $row->base, $row->vat, $row->statedVatCzk), $document->vatRecap()->rows())
            : array_map(static fn (array $row): VatRecapLine => new VatRecapLine(VatRate::of($row['rate']), Money::ofMinor($sign($row['baseMinor']), $currency), Money::ofMinor($sign($row['vatMinor']), $currency)), $rows);
        // The rate of another currency does not carry over; recording takes the published one.
        $sameCurrency = $currency === $document->currency();
        $supplierNumber = $value(ReadField::SupplierNumber);
        $variableSymbol = $value(ReadField::VariableSymbol);

        return new ReceivedDocumentInput(
            $kind,
            $document->treatment(),
            $this->supplier($document, $merged, \in_array(ReadField::SupplierName, $taken, true)),
            \is_string($supplierNumber) ? $supplierNumber : $document->supplierNumber(),
            \is_string($variableSymbol) ? $variableSymbol : $document->variableSymbol(),
            $date(ReadField::IssueDate, $document->issueDate()),
            $date(ReadField::TaxPointDate, $document->taxPointDate()),
            $date(ReadField::DueDate, $document->dueDate()),
            $document->receivedDate(),
            $currency,
            $total,
            $sameCurrency ? $document->exchangeRate() : null,
            $sameCurrency ? $document->exchangeRateDate() : null,
            $recap,
            $document->originalDocumentId(),
            $document->supplyCode(),
        );
    }

    /**
     * The id of the supplier to save: the contact the reading matches when it is taken, else the document's.
     *
     * @throws ReceivedDocumentRefused with SUPPLIER_UNKNOWN when the reading matches no single contact
     */
    private function supplier(ReceivedDocument $document, Reading $merged, bool $taken): ?string
    {
        if (!$taken) {
            return $document->supplierParty()?->id();
        }
        $party = $this->matching->match($merged)->party;

        return $party instanceof Party ? $party->id() : throw new ReceivedDocumentRefused(ReceivedDocumentRefused::SUPPLIER_UNKNOWN);
    }
}
