<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * One step in the life of an invoice: one save, or one action on it.
 */
final readonly class InvoiceHistoryEntry
{
    public const string CREATED = 'created';
    /** Created too, by a recurring template rather than a person's form. */
    public const string ISSUED_FROM_RECURRING = 'issued_recurring';
    public const string CHANGED = 'changed';
    public const string TRASHED = 'trashed';
    public const string RESTORED = 'restored';
    public const string DELIVERED = 'delivered';
    public const string PAID = 'paid';
    public const string PAYMENT_CANCELLED = 'payment_cancelled';
    public const string EXPORTED = 'exported';
    public const string EXPORT_CLEARED = 'export_cleared';

    /**
     * @param string|null              $userName    null when no person did it
     * @param list<string>             $fields      names of the fields of the document that changed
     * @param list<InvoiceHistoryLine> $lines
     * @param string|null              $reason      why it went to the trash
     * @param int|null                 $amountMinor what a payment put on the invoice or took off it, settlement included, in its currency
     */
    public function __construct(
        public \DateTimeImmutable $occurredAt,
        public ?string $userName,
        public string $event,
        public array $fields,
        public array $lines,
        public ?string $reason,
        public ?int $amountMinor = null,
    ) {
    }
}
