<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Application;

/**
 * An occurrence of a planned item that was paid, whose rebill line was not
 * rebilled to the recipient: "paid, not rebilled". The month is the month of
 * the occurrence's day; the recipient is the client's contact id.
 */
final readonly class UnrebilledOccurrence
{
    public function __construct(
        public string $plannedItemId,
        public string $itemName,
        public ?string $itemUrl,
        public \DateTimeImmutable $occurrenceOn,
        public string $month,
        public string $recipientId,
        public string $templateId,
        public string $templateUrl,
        public string $recurringLineId,
    ) {
    }
}
