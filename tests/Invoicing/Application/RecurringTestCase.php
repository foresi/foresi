<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Invoicing\Application\IssueProblem;
use App\Invoicing\Application\LineInput;
use App\Invoicing\Application\RecurringInput;
use App\Invoicing\Application\RecurringInvoices;
use App\Invoicing\Application\RecurringLifecycle;
use App\Invoicing\Application\RecurringRefused;
use App\Invoicing\Application\SaveRecurringInvoice;
use App\Invoicing\Domain\Recurrence;
use App\Invoicing\Domain\RecurrenceEnd;
use App\Invoicing\Domain\RecurrenceUnit;
use App\Invoicing\Domain\RecurringDelivery;
use App\Invoicing\Domain\TaxPointRule;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\PaymentMethod;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * The invoice world of InvoiceTestCase on 2 November 2026 at noon in Prague,
 * with the services of recurring templates.
 */
abstract class RecurringTestCase extends InvoiceTestCase
{
    use ClockSensitiveTrait;

    protected SaveRecurringInvoice $save;
    protected RecurringLifecycle $lifecycle;
    protected RecurringInvoices $templates;

    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime('2026-11-02 11:00:00');
        $container = self::getContainer();
        $this->save = $container->get(SaveRecurringInvoice::class);
        $this->lifecycle = $container->get(RecurringLifecycle::class);
        $this->templates = $container->get(RecurringInvoices::class);
    }

    protected function monthly(string $firstIssueOn = '2026-12-01', RecurrenceEnd $end = RecurrenceEnd::Never, ?string $endOn = null, ?int $endAfter = null, RecurrenceUnit $unit = RecurrenceUnit::Month, int $every = 1): Recurrence
    {
        return new Recurrence(new \DateTimeImmutable($firstIssueOn), $unit, $every, false, $end, null === $endOn ? null : new \DateTimeImmutable($endOn), $endAfter);
    }

    /**
     * @param array<LineInput>|null $lines
     * @param array<string, mixed>  $overrides named arguments of RecurringInput
     */
    protected function recurringInput(?array $lines = null, array $overrides = []): RecurringInput
    {
        return new \ReflectionClass(RecurringInput::class)->newInstanceArgs(array_merge([
            'firmId' => $this->firm->id(),
            'seriesId' => $this->series->id(),
            'customerId' => $this->customer->id(),
            'paymentMethod' => PaymentMethod::BankTransfer,
            'bankAccount' => self::IBAN,
            'constantSymbol' => '0308',
            'orderNumber' => 'PO-{month}',
            'description' => 'Hosting {month/year}',
            'textBefore' => 'Before',
            'textAfter' => 'After',
            'language' => DocumentLanguage::Czech,
            'lines' => $lines ?? [$this->line(text: 'Hosting {month/year}')],
            'recurrence' => $this->monthly(),
            'taxPoint' => TaxPointRule::IssueDate,
            'dueDays' => 14,
            'delivery' => RecurringDelivery::Send,
        ], $overrides));
    }

    /**
     * @return list<string> the keys of the problems of a refused call
     */
    protected function recurringRefusalOf(callable $call): array
    {
        try {
            $call();
        } catch (RecurringRefused $refused) {
            return array_map(static fn (IssueProblem $problem): string => $problem->key, $refused->problems);
        }
        self::fail('Expected the call to be refused.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function templateRow(string $id): array
    {
        $this->em->clear();
        $row = $this->em->getConnection()->fetchAssociative('SELECT * FROM recurring_invoice WHERE id = :id', ['id' => $id]);
        self::assertIsArray($row);

        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function lineRows(string $id): array
    {
        return $this->em->getConnection()->fetchAllAssociative('SELECT * FROM recurring_invoice_line WHERE recurring_invoice_id = :id ORDER BY position', ['id' => $id]);
    }
}
