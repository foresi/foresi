<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Application\Api;

use App\Instance\Application\InstanceClock;
use App\Party\Application\UnknownLegalEntity;
use App\Purchasing\Application\PayReceivedDocument;
use App\Purchasing\Application\PurchasingAccess;
use App\Purchasing\Application\PurchasingRefused;
use App\Purchasing\Application\ReceivedDocumentInput;
use App\Purchasing\Application\ReceivedDocuments;
use App\Purchasing\Application\RecordReceivedDocument;
use App\Purchasing\Application\TrashReceivedDocument;
use App\Purchasing\Application\UnknownReceivedDocument;
use App\Purchasing\Domain\ExchangeRate;
use App\Purchasing\Domain\PaymentMethod;
use App\Purchasing\Domain\PurchaseTaxTreatment;
use App\Purchasing\Domain\ReceivedDocumentKind;
use App\Purchasing\Domain\ReceivedDocumentRefused;
use App\Purchasing\Domain\VatRate;
use App\Purchasing\Domain\VatRecapLine;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;

/**
 * The writes of received documents over the API and MCP: it reads the text of
 * the inputs into dates, amounts and the VAT recap, calls the use cases, and
 * answers what they refuse as PurchasingRefused. Each returns the id of the
 * document to answer with.
 */
final readonly class ReceivedDocumentWrites
{
    public function __construct(
        private RecordReceivedDocument $recording,
        private TrashReceivedDocument $trash,
        private PayReceivedDocument $payments,
        private InstanceClock $today,
        private PurchasingAccess $access,
        private ReceivedDocuments $documents,
    ) {
    }

    /**
     * @throws PurchasingRefused
     * @throws \App\Purchasing\Application\PurchasingNotAllowed
     */
    public function record(RecordReceivedDocumentInput $input): string
    {
        $currency = Currency::tryFrom($input->currency)
            ?? throw PurchasingRefused::unreadable(PurchasingRefused::CURRENCY, 'currency', 'The currency is not an ISO 4217 code Foresi knows, such as CZK or EUR.');

        return $this->refusing(fn (): string => $this->recording->create($input->firmId, new ReceivedDocumentInput(
            ReceivedDocumentKind::from($input->kind),
            PurchaseTaxTreatment::from($input->treatment),
            $input->supplierId,
            $input->supplierNumber,
            $input->variableSymbol,
            $this->day($input->issueDate, 'issueDate'),
            $this->date($input->taxPointDate, 'taxPointDate'),
            $this->date($input->dueDate, 'dueDate'),
            $this->date($input->receivedDate, 'receivedDate') ?? $this->today->today(),
            $currency,
            self::money($input->total, $currency, 'total'),
            null === $input->exchangeRate ? null : ExchangeRate::of($input->exchangeRate),
            null,
            array_map(static fn (string $line): VatRecapLine => self::recapLine($line, $currency), $input->vatRecap),
            $input->originalDocumentId,
            $input->supplyCode,
        ))->document->id());
    }

    /**
     * @throws PurchasingRefused
     * @throws \App\Purchasing\Application\PurchasingNotAllowed
     * @throws UnknownReceivedDocument
     */
    public function trash(TrashReceivedDocumentInput $input): string
    {
        return $this->refusing(fn (): string => $this->trash->trash($input->documentId, $input->reason)->id());
    }

    /**
     * @throws PurchasingRefused
     * @throws \App\Purchasing\Application\PurchasingNotAllowed
     * @throws UnknownReceivedDocument
     */
    public function addPayment(AddReceivedDocumentPaymentInput $input): string
    {
        // The amount is read in the currency of the document.
        $this->access->denyUnlessStaff();
        $currency = ($this->documents->find($input->documentId) ?? throw new UnknownReceivedDocument())->currency();

        return $this->refusing(fn (): string => $this->payments->pay(
            $input->documentId,
            $this->day($input->paidOn, 'paidOn'),
            self::money($input->amount, $currency, 'amount'),
            PaymentMethod::from($input->method),
            $input->bankMovementId,
        )->document()->id());
    }

    /**
     * @throws PurchasingRefused
     * @throws \App\Purchasing\Application\PurchasingNotAllowed
     * @throws UnknownReceivedDocument
     */
    public function markPaid(MarkReceivedDocumentPaidInput $input): string
    {
        return $this->refusing(fn (): string => $this->payments->markPaid($input->documentId, PaymentMethod::from($input->method), $input->bankMovementId)->document()->id());
    }

    /**
     * @throws PurchasingRefused
     * @throws \App\Purchasing\Application\PurchasingNotAllowed
     * @throws UnknownReceivedDocument
     */
    public function removePayment(RemoveReceivedDocumentPaymentInput $input): string
    {
        return $this->refusing(function () use ($input): string {
            $this->payments->remove($input->documentId, $input->paymentId);

            return $input->documentId;
        });
    }

    /**
     * @param callable(): string $write
     */
    private function refusing(callable $write): string
    {
        try {
            return $write();
        } catch (ReceivedDocumentRefused $refused) {
            throw PurchasingRefused::of($refused);
        } catch (UnknownLegalEntity $unknown) {
            throw PurchasingRefused::unknownFirm($unknown);
        }
    }

    private function date(?string $value, string $field): ?\DateTimeImmutable
    {
        return null === $value ? null : $this->day($value, $field);
    }

    private function day(string $value, string $field): \DateTimeImmutable
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (false === $day || $day->format('Y-m-d') !== $value) {
            throw PurchasingRefused::unreadable(PurchasingRefused::DATE, $field, 'The day must be YYYY-MM-DD.');
        }

        return $day;
    }

    private static function money(string $value, Currency $currency, string $field): Money
    {
        if (1 !== preg_match('/^-?\d{1,15}(\.\d{1,'.$currency->minorUnits().'})?$/D', $value)) {
            throw PurchasingRefused::unreadable(PurchasingRefused::AMOUNT, $field, \sprintf('The amount must be a decimal with at most %d places, such as "1210.00".', $currency->minorUnits()));
        }

        return Money::of($value, $currency);
    }

    private static function recapLine(string $line, Currency $currency): VatRecapLine
    {
        $parts = explode(':', $line);
        if (\count($parts) < 3 || \count($parts) > 4) {
            throw PurchasingRefused::unreadable(PurchasingRefused::VAT_RECAP_LINE, 'vatRecap', 'A line of the VAT recap is "rate:base:vat" or "rate:base:vat:stated_vat_czk", such as "21:1000.00:210.00".');
        }

        return new VatRecapLine(
            VatRate::of($parts[0]),
            self::money($parts[1], $currency, 'vatRecap'),
            self::money($parts[2], $currency, 'vatRecap'),
            isset($parts[3]) ? self::money($parts[3], Currency::CZK, 'vatRecap') : null,
        );
    }
}
