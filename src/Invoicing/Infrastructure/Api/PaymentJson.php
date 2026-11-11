<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Api;

use App\Invoicing\Application\Query\PaymentAllocationRow;
use App\Invoicing\Application\Query\PaymentRow;
use App\Shared\Infrastructure\Api\JsonValues;

/**
 * Payments as the REST API and the MCP tools write them, in a list and alone
 * alike. The amounts are positive either way; the direction tells which way the
 * money went.
 */
final class PaymentJson
{
    /**
     * @return array<string, mixed>
     */
    public static function row(PaymentRow $payment): array
    {
        return [
            'id' => $payment->id,
            'firm' => ['id' => $payment->firmId, 'name' => $payment->firmName],
            'received_on' => JsonValues::date($payment->receivedOn),
            'amount' => JsonValues::money($payment->amount),
            'direction' => $payment->direction->value,
            'method' => $payment->method->value,
            'source' => $payment->source->value,
            'note' => $payment->note,
            'bank_movement_id' => $payment->bankMovementId,
            'cancelled_at' => JsonValues::timestamp($payment->cancelledAt),
            'created_at' => JsonValues::timestamp($payment->createdAt),
            'allocations' => array_map(self::allocation(...), $payment->allocations),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function allocation(PaymentAllocationRow $allocation): array
    {
        return [
            'invoice' => ['id' => $allocation->invoiceId, 'number' => $allocation->invoiceNumber],
            'amount' => JsonValues::money($allocation->amount),
            'settlement' => JsonValues::money($allocation->settlement),
        ];
    }
}
