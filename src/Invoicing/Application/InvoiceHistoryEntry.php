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
    public const string CHANGED = 'changed';
    public const string TRASHED = 'trashed';
    public const string RESTORED = 'restored';
    public const string DELIVERED = 'delivered';

    /**
     * @param string|null              $userName null when no person did it
     * @param list<string>             $fields   names of the fields of the document that changed
     * @param list<InvoiceHistoryLine> $lines
     * @param string|null              $reason   why it went to the trash
     */
    public function __construct(
        public \DateTimeImmutable $occurredAt,
        public ?string $userName,
        public string $event,
        public array $fields,
        public array $lines,
        public ?string $reason,
    ) {
    }
}
