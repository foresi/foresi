<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Application;

use App\ExchangeRate\Application\ExchangeRates;
use App\ExchangeRate\Domain\RateNotAvailable;
use App\Party\Application\PartyDetails;
use App\Party\Application\PartyQuery;
use App\Party\Domain\Address;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Purchasing\Domain\ExchangeRate;
use App\Purchasing\Domain\ReceivedDocument;
use App\Purchasing\Domain\ReceivedDocumentContent;
use App\Purchasing\Domain\ReceivedDocumentRefused;
use App\Purchasing\Domain\SupplierSnapshot;
use App\Shared\Domain\Currency;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Turns what was given for a document into its content: the supplier found by
 * id with its snapshot, the original of a credit note checked to be a recorded
 * document of the same firm, and the exchange rate. A rate given wins; a
 * document in another currency without one takes the published rate of its tax
 * point, or of its issue date while it names no tax point (§4(8)).
 */
final readonly class ReceivedDocumentContents
{
    /** The places ExchangeRate keeps after the point. */
    private const int RATE_SCALE = 9;

    public function __construct(
        private PartyQuery $parties,
        private PartyDetails $details,
        private ReceivedDocuments $documents,
        private ExchangeRates $rates,
    ) {
    }

    /**
     * @param ReceivedDocument|null $current  the document being saved, null for a new one
     * @param bool                  $complete whether the document is to be recorded: a rate it needs and cannot get is refused then
     *
     * @throws ReceivedDocumentRefused
     */
    public function of(LegalEntity $firm, ReceivedDocumentInput $input, ?ReceivedDocument $current, bool $complete): ReceivedDocumentContent
    {
        $supplier = $this->supplier($input->supplierId);
        [$rate, $rateDate] = $this->rate($input, $complete);

        return new ReceivedDocumentContent(
            $input->kind,
            $input->treatment,
            $supplier,
            $this->snapshot($supplier, $current),
            $input->supplierNumber,
            $input->variableSymbol,
            $input->issueDate,
            $input->taxPointDate,
            $input->dueDate,
            $input->receivedDate,
            $input->currency,
            $input->total,
            $rate,
            $rateDate,
            $input->vatRecap,
            $this->original($firm, $input->originalDocumentId, $current),
            $input->supplyCode,
        );
    }

    /**
     * @throws ReceivedDocumentRefused with SUPPLIER_UNKNOWN for an id no contact of the tenant has
     */
    private function supplier(?string $id): ?Party
    {
        if (null === $id || '' === $id) {
            return null;
        }

        return $this->parties->find($id) ?? throw new ReceivedDocumentRefused(ReceivedDocumentRefused::SUPPLIER_UNKNOWN);
    }

    /**
     * A recorded document keeps what it froze of its supplier while the supplier
     * stays; a draft, and a document that changes its supplier, take the contact
     * as it is now.
     */
    private function snapshot(?Party $supplier, ?ReceivedDocument $current): SupplierSnapshot
    {
        if (!$supplier instanceof Party) {
            return SupplierSnapshot::none();
        }
        if ($current instanceof ReceivedDocument && $current->isRecorded() && $current->supplierParty()?->id() === $supplier->id()) {
            return $current->supplier();
        }

        return SupplierSnapshot::of($supplier, $this->details->billingAddress($supplier)?->address() ?? new Address('', '', '', ''));
    }

    /**
     * @throws ReceivedDocumentRefused with ORIGINAL_INVALID for anything but another recorded document of the firm
     */
    private function original(LegalEntity $firm, ?string $id, ?ReceivedDocument $current): ?string
    {
        if (null === $id || '' === $id) {
            return null;
        }
        $original = $this->documents->find($id);
        if (!$original instanceof ReceivedDocument || $original === $current || !$original->isRecorded() || $original->isTrashed() || $original->firm()->id() !== $firm->id()) {
            throw new ReceivedDocumentRefused(ReceivedDocumentRefused::ORIGINAL_INVALID);
        }

        return $original->id();
    }

    /**
     * @return array{?ExchangeRate, ?\DateTimeImmutable}
     *
     * @throws ReceivedDocumentRefused with EXCHANGE_RATE_UNAVAILABLE when a document to record has no rate to take
     */
    private function rate(ReceivedDocumentInput $input, bool $complete): array
    {
        if (Currency::CZK === $input->currency || $input->exchangeRate instanceof ExchangeRate) {
            return [$input->exchangeRate, $input->exchangeRateDate];
        }
        $day = $input->taxPointDate ?? $input->issueDate;
        if (!$day instanceof \DateTimeImmutable) {
            // Recording refuses it as missing, the date it would come from too.
            return [null, null];
        }
        try {
            $published = $this->rates->rateOn($input->currency->value, $day);
        } catch (RateNotAvailable) {
            if ($complete) {
                throw new ReceivedDocumentRefused(ReceivedDocumentRefused::EXCHANGE_RATE_UNAVAILABLE);
            }

            return [null, null];
        }
        // The ČNB quotes some currencies per 100 or 1000 units; the document keeps the rate of one.
        $perUnit = BigDecimal::of($published->rate)->dividedBy($published->amount, self::RATE_SCALE, RoundingMode::HalfUp);

        return [ExchangeRate::of($perUnit->toString()), $published->publishedOn];
    }
}
