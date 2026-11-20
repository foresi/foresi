<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Infrastructure\Api;

use Foresi\Party\Application\InstanceFirm;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Purchasing\Application\PaymentBalance;
use Foresi\Purchasing\Application\Query\PaidDocument;
use Foresi\Purchasing\Application\Query\PayableDocument;
use Foresi\Purchasing\Domain\ReceivedDocument;
use Foresi\Purchasing\Domain\ReceivedDocumentPayment;
use Foresi\Purchasing\Domain\VatRecapRow;
use Foresi\Shared\Infrastructure\Api\JsonValues;

/**
 * Received documents as the REST API and the MCP tools write them. Amounts are
 * Money in minor units; paid and remaining are positive, also for a credit
 * note, whose total is negative.
 */
final class ReceivedDocumentJson
{
    /**
     * @param list<PayableDocument> $items
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(array $items, InstanceFirm $firm): array
    {
        // The firm is read once for the slice; before the first-run setup there are no documents.
        $issuer = [] === $items ? null : $firm->get();

        return $issuer instanceof LegalEntity ? array_map(static fn (PayableDocument $item): array => self::row($item, $issuer), $items) : [];
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(PayableDocument $item, LegalEntity $firm): array
    {
        return self::summary($item->document, $item->balance, $firm);
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(PaidDocument $paid, LegalEntity $firm): array
    {
        $document = $paid->document;

        return self::summary($document, $paid->balance, $firm) + [
            'supplier_snapshot' => [
                'name' => $document->supplier()->name,
                'registration_number' => $document->supplier()->registrationNumber,
                'vat_id' => $document->supplier()->vatId,
            ],
            'vat_recap' => array_map(self::recapRow(...), $document->vatRecap()->rows()),
            'exchange_rate' => $document->exchangeRate()?->toString(),
            'exchange_rate_date' => JsonValues::date($document->exchangeRateDate()),
            'deduction_period' => $document->deductionPeriod()?->format('Y-m'),
            'latest_deduction_date' => JsonValues::date($document->latestDeductionDate()),
            'supply_code' => $document->supplyCode(),
            'original_document_id' => $document->originalDocumentId(),
            'trashed_reason' => $document->deletedReason(),
            'payments' => array_map(self::payment(...), $paid->payments),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function payment(ReceivedDocumentPayment $payment): array
    {
        return [
            'id' => $payment->id(),
            'paid_on' => JsonValues::date($payment->paidOn()),
            'amount' => JsonValues::money($payment->amount()),
            'method' => $payment->method()->value,
            'bank_movement_id' => $payment->bankMovementId(),
            'created_at' => JsonValues::timestamp($payment->createdAt()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function summary(ReceivedDocument $document, PaymentBalance $balance, LegalEntity $firm): array
    {
        $total = $document->total();

        return [
            'id' => $document->id(),
            'firm' => ['id' => $firm->id(), 'name' => $firm->name()],
            'state' => $document->state()->value,
            'kind' => $document->kind()->value,
            'treatment' => $document->treatment()->value,
            'evidence_number' => $document->evidenceNumber(),
            'supplier' => $document->supplierParty() instanceof \Foresi\Party\Domain\Party ? ['id' => $document->supplierParty()->id(), 'name' => $document->supplier()->name] : null,
            'supplier_number' => $document->supplierNumber(),
            'variable_symbol' => $document->variableSymbol(),
            'issue_date' => JsonValues::date($document->issueDate()),
            'tax_point_date' => JsonValues::date($document->taxPointDate()),
            'due_date' => JsonValues::date($document->dueDate()),
            'received_date' => JsonValues::date($document->receivedDate()),
            'total' => $total instanceof \Foresi\Shared\Domain\Money ? JsonValues::money($total) : null,
            'base' => JsonValues::money($document->base()),
            'vat' => JsonValues::money($document->vat()),
            'vat_czk' => $document->vatCzk() instanceof \Foresi\Shared\Domain\Money ? JsonValues::money($document->vatCzk()) : null,
            'paid' => JsonValues::money($balance->paid),
            'remaining' => JsonValues::money($balance->remaining),
            'is_paid' => $balance->isPaid(),
            'last_paid_on' => JsonValues::date($balance->lastPaidOn),
            'has_original_file' => null !== $document->originalAttachmentId(),
            'recorded_at' => JsonValues::timestamp($document->recordedAt()),
            'trashed_at' => JsonValues::timestamp($document->deletedAt()),
            'created_at' => JsonValues::timestamp($document->createdAt()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function recapRow(VatRecapRow $row): array
    {
        return [
            'rate' => $row->rate->percent(),
            'base' => JsonValues::money($row->base),
            'vat' => JsonValues::money($row->vat),
            'base_czk' => $row->baseCzk instanceof \Foresi\Shared\Domain\Money ? JsonValues::money($row->baseCzk) : null,
            'vat_czk' => $row->vatCzk instanceof \Foresi\Shared\Domain\Money ? JsonValues::money($row->vatCzk) : null,
        ];
    }
}
