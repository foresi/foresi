<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Purchasing\Unit\Reading;

use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Purchasing\Application\NoDocumentLock;
use Foresi\Purchasing\Application\Reading\ReadingApplication;
use Foresi\Purchasing\Application\Reading\SupplierMatch;
use Foresi\Purchasing\Application\Reading\SupplierMatching;
use Foresi\Purchasing\Application\ReceivedDocumentWarning;
use Foresi\Purchasing\Contract\Reading\ReadField;
use Foresi\Purchasing\Contract\Reading\Reading;
use Foresi\Purchasing\Contract\Reading\ReadValue;
use Foresi\Purchasing\Domain\PurchaseTaxTreatment;
use Foresi\Purchasing\Domain\ReceivedDocument;
use Foresi\Purchasing\Domain\ReceivedDocumentContent;
use Foresi\Purchasing\Domain\ReceivedDocumentKind;
use Foresi\Purchasing\Domain\SupplierSnapshot;
use Foresi\Purchasing\Domain\VatRate;
use Foresi\Purchasing\Domain\VatRecapLine;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Id;
use Foresi\Shared\Domain\Money;
use PHPUnit\Framework\TestCase;

final class ReadingApplicationTest extends TestCase
{
    private const string AT = '2026-11-10 09:00:00';

    /** What a draft takes from a reading, in the order of the fields. */
    private const array APPLICABLE = ['kind', 'supplier_number', 'variable_symbol', 'issue_date', 'tax_point_date', 'due_date', 'currency', 'total', 'vat_recap'];

    public function testAppliesEverythingToAnUntouchedDraft(): void
    {
        $document = $this->draft();
        $base = $document->version();

        $applied = $this->application()->apply($document, $this->reading(), $base);

        self::assertSame(self::APPLICABLE, $this->keys($applied->applied));
        self::assertSame([], $applied->suggested);
        self::assertSame([], $applied->warnings);
        self::assertSame($base + 1, $document->version(), 'One save.');
        self::assertSame(ReceivedDocumentKind::TaxInvoice, $document->kind());
        self::assertSame('FV-2026-77', $document->supplierNumber());
        self::assertSame('2026077', $document->variableSymbol());
        self::assertSame('2026-10-28', $document->issueDate()?->format('Y-m-d'));
        self::assertSame('2026-10-27', $document->taxPointDate()?->format('Y-m-d'));
        self::assertSame('2026-11-11', $document->dueDate()?->format('Y-m-d'));
        self::assertSame('2026-11-02', $document->receivedDate()?->format('Y-m-d'), 'What the reading does not say stays.');
        self::assertSame(12_100, $document->total()?->minor());
        self::assertSame(10_000, $document->base()->minor());
        self::assertSame(2_100, $document->vat()->minor());
        self::assertNull($document->supplierParty(), 'The supplier comes through matching, never from the reading directly.');
        self::assertSame(PurchaseTaxTreatment::Domestic, $document->treatment());
    }

    public function testKeepsSuggestionsWhenTheVersionMoved(): void
    {
        $document = $this->draft();
        $document->saveDraft($this->blankWith(supplierNumber: 'TYPED-1'));
        self::assertSame(3, $document->version());

        $applied = $this->application()->apply($document, $this->reading(), 2);

        self::assertSame([], $applied->applied);
        self::assertSame(self::APPLICABLE, $this->keys($applied->suggested));
        self::assertSame([], $applied->warnings);
        self::assertSame(3, $document->version(), 'Nothing saved.');
        self::assertSame('TYPED-1', $document->supplierNumber(), 'The person\'s value stays.');
        self::assertNull($document->total());
    }

    public function testDropsAmountsThatDoNotAddUp(): void
    {
        $document = $this->draft();
        $reading = $this->reading(total: 12_100, recap: [['rate' => '21', 'baseMinor' => 10_000, 'vatMinor' => 2_000]]);

        $applied = $this->application()->apply($document, $reading, $document->version());

        self::assertSame(['kind', 'supplier_number', 'variable_symbol', 'issue_date', 'tax_point_date', 'due_date'], $this->keys($applied->applied));
        self::assertSame(['currency', 'total', 'vat_recap'], $this->keys($applied->suggested));
        self::assertSame([ReceivedDocumentWarning::READING_PARTLY_APPLIED], $applied->warnings);
        self::assertSame('2026-10-28', $document->issueDate()?->format('Y-m-d'), 'The dates are filled.');
        self::assertNull($document->total(), 'The amounts are not.');
        self::assertTrue($document->vatRecap()->isEmpty());
    }

    public function testDropsAmountsTheDocumentRefuses(): void
    {
        $document = $this->draft();
        // A rate the recap refuses: the amounts go first, and the rest is saved.
        $reading = $this->reading(total: 12_100, recap: [['rate' => '210', 'baseMinor' => 10_000, 'vatMinor' => 2_100]]);

        $applied = $this->application()->apply($document, $reading, $document->version());

        self::assertSame(['currency', 'total', 'vat_recap'], $this->keys($applied->suggested));
        self::assertContains(ReadField::IssueDate, $applied->applied);
        self::assertSame([ReceivedDocumentWarning::READING_PARTLY_APPLIED], $applied->warnings);
    }

    public function testACreditNoteTakesItsAmountsAsNegative(): void
    {
        $document = $this->draft();

        $this->application()->apply($document, $this->reading(kind: 'credit_note'), $document->version());

        self::assertSame(ReceivedDocumentKind::CreditNote, $document->kind());
        self::assertSame(-12_100, $document->total()?->minor());
        self::assertSame(-10_000, $document->base()->minor());
        self::assertSame(-2_100, $document->vat()->minor());
    }

    public function testAForeignCurrencyDropsTheExchangeRateOfTheDraft(): void
    {
        $document = $this->draft();

        $this->application()->apply($document, $this->reading(currency: 'EUR'), $document->version());

        self::assertSame(Currency::EUR, $document->currency());
        self::assertSame(12_100, $document->total()?->minor());
        self::assertNull($document->exchangeRate(), 'The rate of one CZK does not carry over; recording takes the published rate.');
    }

    public function testNeverTouchesARecordedOrTrashedDocument(): void
    {
        $recorded = $this->draft();
        $recorded->assignEvidenceNumber('R20260001');
        $recorded->record($this->complete(), null, new \DateTimeImmutable(self::AT));
        $trashed = $this->draft();
        $trashed->trash(new \DateTimeImmutable(self::AT), null, 'Not ours');

        foreach ([$recorded, $trashed] as $document) {
            $version = $document->version();
            $applied = $this->application()->apply($document, $this->reading(), $version);

            self::assertSame([], $applied->applied);
            self::assertSame(self::APPLICABLE, $this->keys($applied->suggested));
            self::assertSame($version, $document->version());
        }
        self::assertSame('FV-2026-123', $recorded->supplierNumber());
    }

    public function testAReadingWithNothingForTheDraftSavesNothing(): void
    {
        $document = $this->draft();
        $version = $document->version();
        $reading = new Reading([
            new ReadValue(ReadField::SupplierName, 'Supplier Example s.r.o.', 'isdoc'),
            new ReadValue(ReadField::BankAccount, 'CZ6508000000192000145399', 'payment_qr'),
        ]);

        $applied = $this->application()->apply($document, $reading, $version);

        self::assertSame([], $applied->applied);
        self::assertSame([], $applied->suggested, 'The supplier and the account stay in the reading for display.');
        self::assertSame($version, $document->version());
    }

    private function application(): ReadingApplication
    {
        return new ReadingApplication(new NoDocumentLock(), new class implements SupplierMatching {
            public function match(Reading $reading): SupplierMatch
            {
                return SupplierMatch::none();
            }
        });
    }

    /**
     * @param list<array{rate: string, baseMinor: int, vatMinor: int}>|null $recap
     */
    private function reading(string $kind = 'tax_invoice', int $total = 12_100, ?array $recap = null, string $currency = 'CZK'): Reading
    {
        return new Reading([
            new ReadValue(ReadField::Kind, $kind, 'isdoc'),
            new ReadValue(ReadField::SupplierName, 'Supplier Example s.r.o.', 'isdoc'),
            new ReadValue(ReadField::SupplierRegistrationNumber, '87654321', 'isdoc'),
            new ReadValue(ReadField::SupplierNumber, 'FV-2026-77', 'isdoc'),
            new ReadValue(ReadField::VariableSymbol, '2026077', 'isdoc'),
            new ReadValue(ReadField::IssueDate, '2026-10-28', 'isdoc'),
            new ReadValue(ReadField::TaxPointDate, '2026-10-27', 'isdoc'),
            new ReadValue(ReadField::DueDate, '2026-11-11', 'isdoc'),
            new ReadValue(ReadField::Currency, $currency, 'isdoc'),
            new ReadValue(ReadField::Total, $total, 'isdoc'),
            new ReadValue(ReadField::VatRecap, $recap ?? [['rate' => '21', 'baseMinor' => 10_000, 'vatMinor' => 2_100]], 'isdoc'),
            new ReadValue(ReadField::BankAccount, 'CZ6508000000192000145399', 'payment_qr'),
        ]);
    }

    /**
     * An uploaded draft: made blank, then given its original file.
     */
    private function draft(): ReceivedDocument
    {
        $document = new ReceivedDocument(ReceivedDocumentContent::blank(new \DateTimeImmutable('2026-11-02')), Id::generate(), new \DateTimeImmutable(self::AT));
        $document->attachOriginal(Id::generate());

        return $document;
    }

    private function blankWith(string $supplierNumber): ReceivedDocumentContent
    {
        $blank = ReceivedDocumentContent::blank(new \DateTimeImmutable('2026-11-02'));

        return new ReceivedDocumentContent($blank->kind, $blank->treatment, null, SupplierSnapshot::none(), $supplierNumber, '', null, null, null, $blank->receivedDate, Currency::CZK, null, null, null, [], null);
    }

    private function complete(): ReceivedDocumentContent
    {
        $party = new Party(PartyKind::Company, 'Supplier Example s.r.o.', new RegistrationNumber('CZ', '87654321'), null, '');

        return new ReceivedDocumentContent(
            ReceivedDocumentKind::TaxInvoice,
            PurchaseTaxTreatment::Domestic,
            $party,
            SupplierSnapshot::of($party, new Address('Example Street 2', 'Example City', '110 00', 'CZ')),
            'FV-2026-123',
            '2026123',
            new \DateTimeImmutable('2026-10-28'),
            new \DateTimeImmutable('2026-10-28'),
            null,
            new \DateTimeImmutable('2026-11-02'),
            Currency::CZK,
            Money::ofMinor(121_000, Currency::CZK),
            null,
            null,
            [new VatRecapLine(VatRate::of('21'), Money::ofMinor(100_000, Currency::CZK), Money::ofMinor(21_000, Currency::CZK))],
            null,
        );
    }

    /**
     * @param list<ReadField> $fields
     *
     * @return list<string>
     */
    private function keys(array $fields): array
    {
        return array_map(static fn (ReadField $field): string => $field->value, $fields);
    }
}
