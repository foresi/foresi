<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\Invoice;
use App\Party\Application\TransactionalWork;
use Psr\Clock\ClockInterface;

/**
 * Records that a document reached the customer by hand. From then on it cannot be
 * deleted and its series and number are fixed.
 */
final readonly class MarkDelivered
{
    public function __construct(private TransactionalWork $work, private ClockInterface $clock)
    {
    }

    /**
     * @throws \DomainException for an invoice in the trash
     */
    public function mark(Invoice $invoice): void
    {
        $invoice->markDelivered(\DateTimeImmutable::createFromInterface($this->clock->now()));
        $this->work->run(static fn (): null => null);
    }
}
