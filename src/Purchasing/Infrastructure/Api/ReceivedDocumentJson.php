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
use Foresi\Purchasing\Domain\DocumentReading;
use Foresi\Purchasing\Domain\LineView;
use Foresi\Purchasing\Domain\ReceivedDocument;
use Foresi\Purchasing\Domain\ReceivedDocumentPayment;
use Foresi\Purchasing\Domain\VatRecapRow;
use Foresi\Shared\Domain\Money;
use Foresi\Shared\Infrastructure\Api\JsonValues;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Received documents as the REST API and the MCP tools write them. Amounts are
 * Money in minor units; paid and remaining are positive, also for a credit
 * note, whose total is negative. The detail has the lines as reports read
 * them: the stored ones, or one implicit line per rate named by the English
 * name of the document's kind.
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
    public static function detail(PaidDocument $paid, LegalEntity $firm, TranslatorInterface $translator): array
    {
        $document = $paid->document;

        return self::summary($document, $paid->balance, $firm) + [
            'source' => $document->source()->value,
            'reading' => $paid->reading instanceof DocumentReading ? self::reading($paid->reading) : null,
            'supplier_snapshot' => [
                'name' => $document->supplier()->name,
                'registration_number' => $document->supplier()->registrationNumber,
                'vat_id' => $document->supplier()->vatId,
            ],
            'vat_recap' => array_map(self::recapRow(...), $document->vatRecap()->rows()),
            'lines' => array_map(static fn (LineView $line): array => self::line($line, $translator), $document->effectiveLines()),
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
     * The reading of the file: what stands, the sources that read it, each value with the source and
     * whether the draft took it (a value not applied is a suggestion), and the warnings. A failure is
     * a code, never the text of a reader or a provider.
     *
     * @return array<string, mixed>
     */
    public static function reading(DocumentReading $reading): array
    {
        $values = [];
        foreach ($reading->reading()->values as $key => $value) {
            $values[$key] = ['value' => $value->value, 'source' => $value->source, 'applied' => \in_array($key, $reading->applied(), true)];
        }

        return [
            'state' => $reading->state()->value,
            'requested_at' => JsonValues::timestamp($reading->requestedAt()),
            'finished_at' => JsonValues::timestamp($reading->finishedAt()),
            'sources' => array_values(array_unique(array_column($values, 'source'))),
            'values' => [] === $values ? new \stdClass() : $values,
            'warnings' => $reading->warnings(),
            'failure' => $reading->failure(),
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
            'total' => $total instanceof Money ? JsonValues::money($total) : null,
            'base' => JsonValues::money($document->base()),
            'vat' => JsonValues::money($document->vat()),
            'vat_czk' => $document->vatCzk() instanceof Money ? JsonValues::money($document->vatCzk()) : null,
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
    private static function line(LineView $line, TranslatorInterface $translator): array
    {
        return [
            'key' => $line->key,
            // The API speaks English whatever the language of the person.
            'text' => $line->implicit ? $translator->trans('purchasing.kind.'.$line->text, locale: 'en') : $line->text,
            'base' => JsonValues::money($line->base),
            'base_czk' => $line->baseCzk instanceof Money ? JsonValues::money($line->baseCzk) : null,
            'vat_rate' => $line->vatRate,
            'implicit' => $line->implicit,
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
            'base_czk' => $row->baseCzk instanceof Money ? JsonValues::money($row->baseCzk) : null,
            'vat_czk' => $row->vatCzk instanceof Money ? JsonValues::money($row->vatCzk) : null,
        ];
    }
}
