<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Application\View;

use Foresi\Invoicing\Application\MissingTaxDocument;
use Foresi\Invoicing\Application\UncorrectedTaxDocument;
use Foresi\Invoicing\Application\View\TaxDocumentQueueLists;
use Foresi\Invoicing\Application\View\TaxDocumentQueues;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\DefaultView;
use Foresi\Shared\Application\View\Field;
use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use PHPUnit\Framework\TestCase;

/**
 * The two lists a tax document waits in (docs/design/list-pages.md): their fields, their
 * All, and the rows a view and a search keep.
 */
final class TaxDocumentQueueListsTest extends TestCase
{
    public function testEachShipsItsAllAndTheFieldsOfItsColumns(): void
    {
        self::assertEquals([TaxDocumentQueueLists::missing(), TaxDocumentQueueLists::uncorrected()], iterator_to_array(new TaxDocumentQueueLists()->lists()));

        $missing = TaxDocumentQueueLists::missing();
        self::assertSame('invoicing.advances_missing_tax_document', $missing->key);
        self::assertSame([['all', 'invoice.list.tab.all', [], true]], array_map(static fn (DefaultView $view): array => [$view->slug, $view->name, $view->filter->conditions, $view->showCount], $missing->defaults));
        self::assertSame(['customer' => [FieldType::Text, 'advance.missing.column.customer'], 'amount' => [FieldType::Number, 'advance.missing.column.received'], 'received_on' => [FieldType::Date, 'advance.missing.column.receivedOn'], 'deadline' => [FieldType::Date, 'advance.missing.column.deadline']], $this->fields($missing->fields));
        self::assertSame(['contains', 'does_not_contain', 'is'], $missing->fields['customer']->operators, 'a customer always has a name');
        $days = ['is', 'before', 'after', 'on_or_before', 'on_or_after', 'between'];
        self::assertSame($days, $missing->fields['deadline']->operators, 'a row always has a deadline');
        self::assertSame($days, $missing->fields['received_on']->operators);

        $uncorrected = TaxDocumentQueueLists::uncorrected();
        self::assertSame('invoicing.tax_documents_uncorrected', $uncorrected->key);
        self::assertSame(['all'], array_map(static fn (DefaultView $view): string => $view->slug, $uncorrected->defaults));
        self::assertSame(['customer' => [FieldType::Text, 'tax_document.uncorrected.column.customer'], 'amount' => [FieldType::Number, 'tax_document.uncorrected.column.amount'], 'decisive_date' => [FieldType::Date, 'tax_document.uncorrected.column.decisiveDate'], 'deadline' => [FieldType::Date, 'tax_document.uncorrected.column.deadline'], 'deducted' => [FieldType::Boolean, 'tax_document.uncorrected.field.deducted']], $this->fields($uncorrected->fields));
        self::assertSame(['contains', 'does_not_contain', 'is'], $uncorrected->fields['customer']->operators);
        self::assertSame($days, $uncorrected->fields['deadline']->operators);
        self::assertSame($days, $uncorrected->fields['decisive_date']->operators);
    }

    public function testAViewAndASearchKeepTheirRows(): void
    {
        $today = new \DateTimeImmutable('2026-11-18');
        $early = $this->missing('Z920260001', 'Acme Example s.r.o.', '1210.00', '2026-11-10');
        $late = $this->missing('Z920260002', 'Šťastný Example a.s.', '50.00', '2026-11-30', '2026-11-16');
        $rows = [$early, $late];
        $key = TaxDocumentQueueLists::MISSING;

        self::assertSame([$early], TaxDocumentQueues::matching($rows, $key, ViewFilter::all(new Condition('amount', 'gt', 1000)), $today), 'an amount in whole units');
        self::assertSame([$late], TaxDocumentQueues::matching($rows, $key, ViewFilter::all(new Condition('deadline', 'after', ['relative' => 'today'])), $today));
        self::assertSame([$early], TaxDocumentQueues::matching($rows, $key, ViewFilter::all(new Condition('received_on', 'is', '2026-11-01')), $today));
        self::assertSame([$late], TaxDocumentQueues::matching($rows, $key, ViewFilter::all(new Condition('customer', 'contains', 'šťastný')), $today));
        self::assertSame($rows, TaxDocumentQueues::searched($rows, ''));
        self::assertSame([$late], TaxDocumentQueues::searched($rows, 'z920260002'));
        self::assertSame([$early], TaxDocumentQueues::searched($rows, 'ACME'));
        self::assertSame([], TaxDocumentQueues::searched($rows, 'Z920260001 Šťastný'));
        self::assertSame([$early], TaxDocumentQueues::searched($rows, 'Z920260001 Acme'), 'the number, a space, the customer');
        self::assertSame([$late], TaxDocumentQueues::searched($rows, 'ŠŤASTNÝ'), 'whatever the case of a letter with a mark');
        self::assertSame([$late], TaxDocumentQueues::searched($rows, 'šťastný'));

        $deducted = $this->uncorrected('D820260001', true);
        $plain = $this->uncorrected('D820260002', false);
        $documents = [$deducted, $plain];
        $key = TaxDocumentQueueLists::UNCORRECTED;
        self::assertSame([$deducted], TaxDocumentQueues::matching($documents, $key, ViewFilter::all(new Condition('deducted', 'is', true)), $today));
        self::assertSame([$plain], TaxDocumentQueues::matching($documents, $key, ViewFilter::all(new Condition('deducted', 'is', false)), $today));
        self::assertSame($documents, TaxDocumentQueues::matching($documents, $key, ViewFilter::all(new Condition('decisive_date', 'is', '2026-11-05'), new Condition('amount', 'eq', 121)), $today));
        self::assertSame([$plain], TaxDocumentQueues::searched($documents, 'd820260002'));
        self::assertSame('D820260001', TaxDocumentQueues::number($deducted));
        self::assertSame('Z920260001', TaxDocumentQueues::number($early));
    }

    public function testAFieldOfTheOtherListHasNoValue(): void
    {
        $missing = $this->missing('Z920260001', 'Acme Example s.r.o.', '10.00', '2026-11-10');
        $uncorrected = $this->uncorrected('D820260001', true);

        self::assertNull(TaxDocumentQueues::valueOf($missing, 'decisive_date'));
        self::assertFalse(TaxDocumentQueues::valueOf($missing, 'deducted'));
        self::assertNull(TaxDocumentQueues::valueOf($uncorrected, 'received_on'));
        $this->expectException(\LogicException::class);
        TaxDocumentQueues::valueOf($missing, 'unknown');
    }

    /**
     * @param array<string, Field> $fields
     *
     * @return array<string, array{FieldType, string}>
     */
    private function fields(array $fields): array
    {
        return array_map(static fn (Field $field): array => [$field->type, $field->label], $fields);
    }

    private function missing(string $number, string $customer, string $amount, string $deadline, string $receivedOn = '2026-11-01'): MissingTaxDocument
    {
        return new MissingTaxDocument('a-'.$number, $number, 'Example Firm s.r.o.', $customer, 'p-'.$number, new \DateTimeImmutable($receivedOn), Money::of($amount, Currency::CZK), new \DateTimeImmutable($deadline), 0);
    }

    private function uncorrected(string $number, bool $deducted): UncorrectedTaxDocument
    {
        return new UncorrectedTaxDocument('d-'.$number, $number, 'Example Customer a.s.', Money::of('121.00', Currency::CZK), false, $deducted, new \DateTimeImmutable('2026-11-05'), new \DateTimeImmutable('2026-11-20'), 2);
    }
}
