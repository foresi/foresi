<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Doctrine;

use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\InvoiceSend;
use App\Invoicing\Domain\RecurringState;
use App\Invoicing\Domain\SendPurpose;
use App\Invoicing\Domain\SendStatus;
use Doctrine\ORM\Query;

/**
 * When a template counts as failing, in DQL, for its count, its tab and its rows
 * alike: it has not ended and its last run was refused. A refused send ("send."
 * codes) counts only while an invoice of the template still waits to be sent, as
 * the To send list has it; once someone sent it, it is on its way, or it was
 * marked delivered, the failure is over, without waiting for the next run to
 * clear the stored code. A refused issue counts until a run goes clean.
 */
final class RecurringFailing
{
    /**
     * @param string $template the alias of the RecurringInvoice in the query
     * @param string $suffix   for the aliases of the subqueries, when the condition appears twice in one query
     */
    public static function dql(string $template, string $suffix = ''): string
    {
        return \sprintf(
            "(%1\$s.state <> :failingEnded AND %1\$s.lastFailureCode IS NOT NULL AND (%1\$s.lastFailureCode NOT LIKE 'send.%%' OR EXISTS ("
            .'SELECT fi%4$s.id FROM %2$s fi%4$s WHERE fi%4$s.recurringInvoiceId = %1$s.id AND fi%4$s.deletedAt IS NULL AND fi%4$s.deliveredAt IS NULL'
            .' AND NOT EXISTS (SELECT fs%4$s.id FROM %3$s fs%4$s WHERE fs%4$s.invoice = fi%4$s AND fs%4$s.purpose = :failingPurpose AND fs%4$s.status IN (:failingDelivering))'
            .')))',
            $template,
            Invoice::class,
            InvoiceSend::class,
            $suffix,
        );
    }

    /**
     * @param Query<mixed, mixed> $query
     */
    public static function bind(Query $query): void
    {
        $query->setParameter('failingEnded', RecurringState::Ended->value)
            ->setParameter('failingPurpose', SendPurpose::Invoice->value)
            ->setParameter('failingDelivering', [SendStatus::Queued->value, SendStatus::Sent->value]);
    }
}
