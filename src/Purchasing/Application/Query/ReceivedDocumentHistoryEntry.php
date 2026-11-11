<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Application\Query;

/**
 * One step in the history of a received document: who did what and when. A
 * change names the fields it changed by the last part of their translation key
 * under purchasing.field; a move to the trash keeps its reason.
 */
final readonly class ReceivedDocumentHistoryEntry
{
    public const string CREATED = 'created';
    public const string RECORDED = 'recorded';
    public const string CHANGED = 'changed';
    public const string TRASHED = 'trashed';
    public const string RESTORED = 'restored';
    public const array EVENTS = [self::CREATED, self::RECORDED, self::CHANGED, self::TRASHED, self::RESTORED];

    /**
     * @param list<string> $fields
     */
    public function __construct(
        public \DateTimeImmutable $occurredAt,
        public ?string $userName,
        public string $event,
        public array $fields = [],
        public ?string $reason = null,
    ) {
    }
}
