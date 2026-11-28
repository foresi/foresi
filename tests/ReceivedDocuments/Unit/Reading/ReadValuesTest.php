<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\ReceivedDocuments\Unit\Reading;

use Foresi\Party\Domain\Address;
use Foresi\ReceivedDocuments\Application\Reading\ReadValues;
use Foresi\ReceivedDocuments\Contract\Reading\ReadField;
use Foresi\ReceivedDocuments\Contract\Reading\Reading;
use Foresi\ReceivedDocuments\Contract\Reading\ReadValue;
use Foresi\ReceivedDocuments\Domain\ReceivedDocument;
use Foresi\ReceivedDocuments\Domain\ReceivedDocumentContent;
use Foresi\ReceivedDocuments\Domain\ReceivedDocumentKind;
use Foresi\ReceivedDocuments\Domain\SupplierSnapshot;
use Foresi\ReceivedDocuments\Domain\VatRate;
use Foresi\ReceivedDocuments\Domain\VatRecapLine;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Id;
use Foresi\Shared\Domain\Money;
use PHPUnit\Framework\TestCase;

final class ReadValuesTest extends TestCase
{
    private const string AT = '2026-11-10 09:00:00';

    public function testEachDateFieldNeedsARealDay(): void
    {
        foreach ([ReadField::IssueDate, ReadField::TaxPointDate, ReadField::DueDate] as $field) {
            self::assertTrue(ReadValues::shaped($this->reading([$field->value => '2026-10-28']), $field), $field->value);
            self::assertFalse(ReadValues::shaped($this->reading([$field->value => '2026-13-45']), $field), $field->value);
            self::assertFalse(ReadValues::shaped($this->reading([$field->value => 20261028]), $field), $field->value);
        }
    }

    public function testTheKindMustBeOneOfTheKinds(): void
    {
        self::assertTrue(ReadValues::shaped($this->reading(['kind' => 'credit_note']), ReadField::Kind));
        self::assertFalse(ReadValues::shaped($this->reading(['kind' => 'bogus']), ReadField::Kind));
        self::assertFalse(ReadValues::shaped($this->reading([]), ReadField::Kind));
    }

    public function testAnUnsupportedCurrencyIsForeign(): void
    {
        self::assertTrue(ReadValues::foreignCurrency($this->reading(['currency' => 'XXX'])));
        self::assertFalse(ReadValues::foreignCurrency($this->reading(['currency' => 'EUR'])));
        self::assertFalse(ReadValues::foreignCurrency($this->reading([])));
        self::assertTrue(ReadValues::blockedAmount($this->reading(['currency' => 'XXX']), ReadField::Total));
        self::assertFalse(ReadValues::blockedAmount($this->reading(['currency' => 'XXX']), ReadField::IssueDate));
    }

    public function testADateIsARealDayWrittenAsYmd(): void
    {
        self::assertSame('2026-10-28', ReadValues::date('2026-10-28')?->format('Y-m-d'));
        self::assertNull(ReadValues::date('garbage'));
        self::assertNull(ReadValues::date('2026-02-30'));
        self::assertNull(ReadValues::date(20261028));
    }

    public function testARecapIsANonEmptyListOfWholeRows(): void
    {
        $row = ['rate' => '21', 'baseMinor' => 10_000, 'vatMinor' => 2_100];

        self::assertSame([$row], ReadValues::recap([$row + ['extra' => 'dropped']]));
        self::assertNull(ReadValues::recap([]));
        self::assertNull(ReadValues::recap('text'));
        self::assertNull(ReadValues::recap(['first' => $row]));
        self::assertNull(ReadValues::recap([$row, 'not a row']));
        self::assertNull(ReadValues::recap([['baseMinor' => 10_000, 'vatMinor' => 2_100]]));
        self::assertNull(ReadValues::recap([['rate' => 21, 'baseMinor' => 10_000, 'vatMinor' => 2_100]]));
        self::assertNull(ReadValues::recap([['rate' => '21', 'baseMinor' => '10000', 'vatMinor' => 2_100]]));
        self::assertNull(ReadValues::recap([['rate' => '21', 'baseMinor' => 10_000]]));
        self::assertNull(ReadValues::recap([['rate' => '21', 'baseMinor' => 10_000, 'vatMinor' => 21.0]]));
    }

    public function testADraftHoldsWhatItHasOfTheReading(): void
    {
        $document = $this->document(new ReceivedDocumentContent(
            ReceivedDocumentKind::CreditNote,
            $this->blank()->treatment,
            null,
            SupplierSnapshot::none(),
            'FV-1',
            '2026077',
            new \DateTimeImmutable('2026-10-28'),
            new \DateTimeImmutable('2026-10-27'),
            new \DateTimeImmutable('2026-11-11'),
            null,
            Currency::of('EUR'),
            Money::ofMinor(-12_100, Currency::of('EUR')),
            null,
            null,
            [new VatRecapLine(VatRate::of('21'), Money::ofMinor(-10_000, Currency::of('EUR')), Money::ofMinor(-2_100, Currency::of('EUR')))],
            null,
        ));
        $holds = fn (ReadField $field, string|int|array $value): bool => ReadValues::holds($document, $this->reading([$field->value => $value]), $field);

        self::assertTrue($holds(ReadField::Kind, 'credit_note'));
        self::assertFalse($holds(ReadField::Kind, 'tax_invoice'));
        self::assertTrue($holds(ReadField::SupplierNumber, 'FV-1'));
        self::assertFalse($holds(ReadField::SupplierNumber, 'FV-2'));
        self::assertTrue($holds(ReadField::VariableSymbol, '2026077'));
        self::assertFalse($holds(ReadField::VariableSymbol, '2026078'));
        self::assertTrue($holds(ReadField::IssueDate, '2026-10-28'));
        self::assertFalse($holds(ReadField::IssueDate, '2026-10-29'));
        self::assertTrue($holds(ReadField::TaxPointDate, '2026-10-27'));
        self::assertFalse($holds(ReadField::TaxPointDate, '2026-10-28'));
        self::assertTrue($holds(ReadField::DueDate, '2026-11-11'));
        self::assertFalse($holds(ReadField::DueDate, '2026-11-12'));
        self::assertTrue($holds(ReadField::Currency, 'EUR'));
        self::assertFalse($holds(ReadField::Currency, 'CZK'));
        // An amount compares by its size: a credit note holds it negative.
        self::assertTrue($holds(ReadField::Total, 12_100));
        self::assertFalse($holds(ReadField::Total, 12_101));
        self::assertTrue($holds(ReadField::VatRecap, [['rate' => '21', 'baseMinor' => 10_000, 'vatMinor' => 2_100]]));
        self::assertFalse($holds(ReadField::VatRecap, [['rate' => '21', 'baseMinor' => 10_000, 'vatMinor' => 2_101]]));
        self::assertFalse($holds(ReadField::VatRecap, [['rate' => '21', 'baseMinor' => 10_000, 'vatMinor' => 2_100], ['rate' => '10', 'baseMinor' => 1, 'vatMinor' => 0]]));
        self::assertFalse($holds(ReadField::VatRecap, [['rate' => '210', 'baseMinor' => 10_000, 'vatMinor' => 2_100]]));
        self::assertFalse($holds(ReadField::VatRecap, 'text'));
        self::assertFalse($holds(ReadField::Total, 'text'));
        // A field the draft has no place for is never held.
        self::assertFalse($holds(ReadField::BankAccount, 'CZ6508000000192000145399'));
        // A field the reading does not have is not held.
        self::assertFalse(ReadValues::holds($document, $this->reading([]), ReadField::Kind));
        self::assertFalse(ReadValues::holds($document, $this->reading([]), ReadField::SupplierName));
    }

    public function testADraftWithoutValuesHoldsNothing(): void
    {
        $document = $this->document($this->blank());

        self::assertFalse(ReadValues::holds($document, $this->reading(['issue_date' => '2026-10-28']), ReadField::IssueDate));
        self::assertFalse(ReadValues::holds($document, $this->reading(['tax_point_date' => '2026-10-28']), ReadField::TaxPointDate));
        self::assertFalse(ReadValues::holds($document, $this->reading(['due_date' => '2026-10-28']), ReadField::DueDate));
        self::assertFalse(ReadValues::holds($document, $this->reading(['total' => 12_100]), ReadField::Total));
        self::assertFalse(ReadValues::holds($document, $this->reading(['vat_recap' => [['rate' => '21', 'baseMinor' => 10_000, 'vatMinor' => 2_100]]]), ReadField::VatRecap));
    }

    public function testTheSupplierIsHeldByAnyOfItsNames(): void
    {
        $document = $this->documentOf(new SupplierSnapshot('Ünïcode Hosting s.r.o.', '12345678', 'CZ12345678', new Address('', '', '', 'CZ')));

        self::assertTrue($this->holdsSupplier($document, ['supplier_name' => 'ÜNÏCODE  hosting s.r.o.']));
        self::assertFalse($this->holdsSupplier($document, ['supplier_name' => 'Other Hosting s.r.o.']));
        self::assertTrue($this->holdsSupplier($document, ['supplier_name' => 'Other Hosting s.r.o.', 'supplier_registration_number' => '1234 5678']));
        self::assertFalse($this->holdsSupplier($document, ['supplier_name' => 'Other Hosting s.r.o.', 'supplier_registration_number' => '999']));
        // A reading that names no supplier holds nothing, whatever its numbers.
        self::assertFalse($this->holdsSupplier($document, ['supplier_registration_number' => '12345678']));
        self::assertTrue($this->holdsSupplier($document, ['supplier_name' => 'Other Hosting s.r.o.', 'supplier_vat_id' => 'cz 12345678']));
        self::assertTrue($this->holdsSupplier($document, ['supplier_name' => 'Other Hosting s.r.o.', 'supplier_vat_id' => 'CZ12345678']));
        self::assertFalse($this->holdsSupplier($document, ['supplier_name' => 'Other Hosting s.r.o.', 'supplier_vat_id' => 'CZ999']));
        self::assertFalse($this->holdsSupplier($document, ['supplier_name' => 'Other Hosting s.r.o.', 'supplier_registration_number' => '999', 'supplier_vat_id' => 'CZ999']));
        self::assertFalse($this->holdsSupplier($document, ['supplier_name' => 42]));
    }

    public function testASupplierWithoutNumbersIsNotHeldByTheirAbsence(): void
    {
        $document = $this->documentOf(new SupplierSnapshot('Cloud Host s.r.o.', null, null, new Address('', '', '', 'CZ')));

        self::assertFalse(ReadValues::holds($document, $this->reading(['supplier_name' => 'Other Hosting s.r.o.']), ReadField::SupplierName));
        self::assertFalse(ReadValues::holds($document, $this->reading(['supplier_name' => 'Other Hosting s.r.o.', 'supplier_registration_number' => '999']), ReadField::SupplierName));
        self::assertTrue(ReadValues::holds($document, $this->reading(['supplier_name' => 'Cloud Host s.r.o.']), ReadField::SupplierName));
    }

    /**
     * @param array<string, string|int|array<mixed>> $values
     */
    private function holdsSupplier(ReceivedDocument $document, array $values): bool
    {
        return ReadValues::holds($document, $this->reading($values), ReadField::SupplierName);
    }

    /**
     * @param array<string, string|int|array<mixed>> $values
     */
    private function reading(array $values): Reading
    {
        $read = [];
        foreach ($values as $key => $value) {
            $field = ReadField::from($key);
            $read[$field->value] = new ReadValue($field, $value, 'isdoc');
        }

        return new Reading($read);
    }

    private function blank(): ReceivedDocumentContent
    {
        return ReceivedDocumentContent::blank(new \DateTimeImmutable('2026-11-02'));
    }

    private function document(ReceivedDocumentContent $content): ReceivedDocument
    {
        $document = new ReceivedDocument($this->blank(), Id::generate(), new \DateTimeImmutable(self::AT));
        $document->saveDraft($content);

        return $document;
    }

    private function documentOf(SupplierSnapshot $supplier): ReceivedDocument
    {
        $blank = $this->blank();

        return $this->document(new ReceivedDocumentContent($blank->kind, $blank->treatment, null, $supplier, '', '', null, null, null, $blank->receivedDate, Currency::of('CZK'), null, null, null, [], null));
    }
}
