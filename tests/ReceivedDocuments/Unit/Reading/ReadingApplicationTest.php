<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\ReceivedDocuments\Unit\Reading;

use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\ReceivedDocuments\Application\NoDocumentLock;
use Foresi\ReceivedDocuments\Application\Reading\ReadingApplication;
use Foresi\ReceivedDocuments\Application\Reading\SupplierMatch;
use Foresi\ReceivedDocuments\Application\Reading\SupplierMatching;
use Foresi\ReceivedDocuments\Application\ReceivedDocumentWarning;
use Foresi\ReceivedDocuments\Contract\Reading\ReadField;
use Foresi\ReceivedDocuments\Contract\Reading\Reading;
use Foresi\ReceivedDocuments\Contract\Reading\ReadValue;
use Foresi\ReceivedDocuments\Domain\PurchaseTaxTreatment;
use Foresi\ReceivedDocuments\Domain\ReceivedDocument;
use Foresi\ReceivedDocuments\Domain\ReceivedDocumentContent;
use Foresi\ReceivedDocuments\Domain\ReceivedDocumentKind;
use Foresi\ReceivedDocuments\Domain\SupplierSnapshot;
use Foresi\ReceivedDocuments\Domain\VatRate;
use Foresi\ReceivedDocuments\Domain\VatRecapLine;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Id;
use Foresi\Shared\Domain\Money;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testAMatchedSupplierIsWrittenWithTheValues(): void
    {
        $document = $this->draft();
        $party = $this->party();

        $applied = $this->application($this->matched($party))->apply($document, $this->reading(), $document->version());

        self::assertSame([...self::APPLICABLE, 'supplier_name'], $this->keys($applied->applied));
        self::assertSame([], $applied->suggested);
        self::assertSame($party, $document->supplierParty());
        self::assertSame('Supplier Example s.r.o.', $document->supplier()->name);
    }

    public function testATouchedDraftOnlyGetsTheMatchAsASuggestion(): void
    {
        $document = $this->draft();
        $document->saveDraft($this->blankWith(supplierNumber: 'TYPED-1'));

        $applied = $this->application($this->matched($this->party()))->apply($document, $this->reading(), 2);

        self::assertSame([], $applied->applied);
        self::assertSame([...self::APPLICABLE, 'supplier_name'], $this->keys($applied->suggested));
        self::assertNull($document->supplierParty());
    }

    public function testAMatchWithoutASnapshotWritesNoSupplier(): void
    {
        $document = $this->draft();

        $applied = $this->application(new SupplierMatch($this->party()))->apply($document, $this->reading(), $document->version());

        self::assertSame(self::APPLICABLE, $this->keys($applied->applied));
        self::assertNull($document->supplierParty());
    }

    public function testAMatchWithAWarningButNotTheNotFoundOneWritesNoSupplier(): void
    {
        $document = $this->draft();

        $applied = $this->application(new SupplierMatch(null, ReceivedDocumentWarning::SUPPLIER_AMBIGUOUS))->apply($document, $this->reading(), $document->version());

        self::assertSame(self::APPLICABLE, $this->keys($applied->applied));
        self::assertSame([ReceivedDocumentWarning::SUPPLIER_AMBIGUOUS], $applied->warnings);
        self::assertSame('', $document->supplier()->name);
    }

    public function testWithNobodyFoundTheSnapshotTakesWhatTheReadingNames(): void
    {
        $document = $this->draft();
        $reading = $this->reading(extra: [
            new ReadValue(ReadField::SupplierName, '  Supplier   Example s.r.o.  ', 'isdoc'),
            new ReadValue(ReadField::SupplierRegistrationNumber, ' 87654321 ', 'isdoc'),
            new ReadValue(ReadField::SupplierVatId, 'cz 87654321', 'isdoc'),
            new ReadValue(ReadField::SupplierCountry, 'cz', 'isdoc'),
        ]);

        $applied = $this->application($this->notFound())->apply($document, $reading, $document->version());

        self::assertSame(
            [...self::APPLICABLE, 'supplier_name', 'supplier_registration_number', 'supplier_vat_id', 'supplier_country'],
            $this->keys($applied->applied),
        );
        self::assertSame([ReceivedDocumentWarning::SUPPLIER_NOT_FOUND], $applied->warnings);
        self::assertNull($document->supplierParty());
        self::assertSame('Supplier Example s.r.o.', $document->supplier()->name);
        self::assertSame('87654321', $document->supplier()->registrationNumber);
        self::assertSame('CZ87654321', $document->supplier()->vatId);
        self::assertSame('CZ', $document->supplier()->address->countryCode);
    }

    public function testTheSnapshotIsCappedAtTheColumnsOfTheDocument(): void
    {
        $document = $this->draft();
        $reading = new Reading([
            new ReadValue(ReadField::SupplierName, str_repeat('Ü', 300), 'isdoc'),
            new ReadValue(ReadField::SupplierRegistrationNumber, str_repeat('7', 70), 'isdoc'),
            new ReadValue(ReadField::SupplierCountry, 'CZE', 'isdoc'),
        ]);

        $this->application($this->notFound())->apply($document, $reading, $document->version());

        self::assertSame(str_repeat('Ü', SupplierSnapshot::MAX_NAME), $document->supplier()->name);
        self::assertSame(str_repeat('7', SupplierSnapshot::MAX_REGISTRATION_NUMBER), $document->supplier()->registrationNumber);
        self::assertSame('CZ', $document->supplier()->address->countryCode);
    }

    /**
     * @return iterable<string, array{list<ReadValue>, list<string>}>
     */
    public static function partialSuppliers(): iterable
    {
        yield 'only a name' => [[new ReadValue(ReadField::SupplierName, 'Supplier Example s.r.o.', 'isdoc')], ['supplier_name']];
        yield 'only a registration number' => [[new ReadValue(ReadField::SupplierRegistrationNumber, '87654321', 'isdoc')], ['supplier_registration_number']];
        yield 'only a VAT id' => [[new ReadValue(ReadField::SupplierVatId, 'CZ87654321', 'isdoc')], ['supplier_vat_id']];
        yield 'a name and a country' => [
            [new ReadValue(ReadField::SupplierName, 'Supplier Example s.r.o.', 'isdoc'), new ReadValue(ReadField::SupplierCountry, 'DE', 'isdoc')],
            ['supplier_name', 'supplier_country'],
        ];
        yield 'a number and an unusable VAT id' => [
            [new ReadValue(ReadField::SupplierRegistrationNumber, '87654321', 'isdoc'), new ReadValue(ReadField::SupplierVatId, 'not a vat id!', 'isdoc')],
            ['supplier_registration_number'],
        ];
        yield 'a blank name and a number' => [
            [new ReadValue(ReadField::SupplierName, "  \n", 'isdoc'), new ReadValue(ReadField::SupplierRegistrationNumber, '87654321', 'isdoc')],
            ['supplier_registration_number'],
        ];
    }

    /**
     * @param list<ReadValue> $values
     * @param list<string>    $written
     */
    #[DataProvider('partialSuppliers')]
    public function testOnlyWhatTheReadingNamesIsWritten(array $values, array $written): void
    {
        $document = $this->draft();

        $applied = $this->application($this->notFound())->apply($document, new Reading($values), $document->version());

        self::assertSame($written, $this->keys($applied->applied));
        self::assertSame([ReceivedDocumentWarning::SUPPLIER_NOT_FOUND], $applied->warnings);
    }

    /**
     * @return iterable<string, array{list<ReadValue>}>
     */
    public static function unusableSuppliers(): iterable
    {
        yield 'only a country' => [[new ReadValue(ReadField::SupplierCountry, 'DE', 'isdoc')]];
        yield 'an unusable VAT id' => [[new ReadValue(ReadField::SupplierVatId, 'not a vat id!', 'isdoc')]];
        yield 'blank texts' => [[new ReadValue(ReadField::SupplierName, '   ', 'isdoc'), new ReadValue(ReadField::SupplierRegistrationNumber, ' ', 'isdoc')]];
        yield 'nothing' => [[]];
    }

    /**
     * @param list<ReadValue> $values
     */
    #[DataProvider('unusableSuppliers')]
    public function testWithoutAnythingToIdentifyTheSupplierNothingIsWritten(array $values): void
    {
        $document = $this->draft();

        $applied = $this->application($this->notFound())->apply($document, new Reading($values), $document->version());

        self::assertSame([], $applied->applied);
        self::assertSame('', $document->supplier()->name);
        self::assertSame('', $document->supplier()->address->countryCode);
        self::assertSame([ReceivedDocumentWarning::SUPPLIER_NOT_FOUND], $applied->warnings);
    }

    public function testAReadingWithOnlyBlockedAmountsLeavesThemAsSuggestions(): void
    {
        $document = $this->draft();
        $reading = new Reading([
            new ReadValue(ReadField::Currency, 'XXX', 'isdoc'),
            new ReadValue(ReadField::Total, 12_100, 'isdoc'),
            new ReadValue(ReadField::VatRecap, [['rate' => '21', 'baseMinor' => 10_000, 'vatMinor' => 2_100]], 'isdoc'),
        ]);

        $applied = $this->application()->apply($document, $reading, $document->version());

        self::assertSame([], $applied->applied);
        self::assertSame(['total', 'vat_recap'], $this->keys($applied->suggested));
        self::assertSame([ReceivedDocumentWarning::READING_PARTLY_APPLIED], $applied->warnings);
        self::assertNull($document->total());
    }

    public function testAReadingWithNothingToApplyGivesNoWarning(): void
    {
        $document = $this->draft();

        $applied = $this->application()->apply($document, new Reading([new ReadValue(ReadField::BankAccount, 'CZ6508000000192000145399', 'payment_qr')]), $document->version());

        self::assertSame([], $applied->applied);
        self::assertSame([], $applied->suggested);
        self::assertSame([], $applied->warnings);
    }

    public function testBlockedAmountsAreKeptBackWhenTheRestIsApplied(): void
    {
        $document = $this->draft();
        $reading = new Reading([
            new ReadValue(ReadField::IssueDate, '2026-10-28', 'isdoc'),
            new ReadValue(ReadField::Currency, 'XXX', 'isdoc'),
            new ReadValue(ReadField::Total, 12_100, 'isdoc'),
        ]);

        $applied = $this->application()->apply($document, $reading, $document->version());

        self::assertSame(['issue_date'], $this->keys($applied->applied));
        self::assertSame(['total'], $this->keys($applied->suggested));
        self::assertSame([ReceivedDocumentWarning::READING_PARTLY_APPLIED], $applied->warnings);
    }

    /**
     * @return iterable<string, array{ReceivedDocumentContent, list<string>}>
     */
    public static function typedDrafts(): iterable
    {
        $with = self::typed(...);
        $day = new \DateTimeImmutable('2026-10-01');

        yield 'a typed kind' => [$with(['kind' => ReceivedDocumentKind::SimplifiedReceipt]), ['kind']];
        yield 'a typed supplier number' => [$with(['supplierNumber' => 'TYPED-1']), ['supplier_number']];
        yield 'a typed variable symbol' => [$with(['variableSymbol' => '99']), ['variable_symbol']];
        yield 'a typed issue date' => [$with(['issueDate' => $day]), ['issue_date']];
        yield 'a typed tax point date' => [$with(['taxPointDate' => $day]), ['tax_point_date']];
        yield 'a typed due date' => [$with(['dueDate' => $day]), ['due_date']];
        yield 'a typed total' => [$with(['total' => Money::ofMinor(500, Currency::CZK)]), ['currency', 'total', 'vat_recap']];
        yield 'a typed recap' => [$with(['vatRecap' => [new VatRecapLine(VatRate::of('21'), Money::ofMinor(100, Currency::CZK), Money::ofMinor(21, Currency::CZK))]]), ['currency', 'total', 'vat_recap']];
        yield 'a zero total is not typed' => [$with(['total' => Money::ofMinor(0, Currency::CZK)]), []];
        yield 'blank texts are not typed' => [$with(['supplierNumber' => '  ', 'variableSymbol' => " \n"]), []];
        yield 'a kind like the one read is not typed' => [$with(['kind' => ReceivedDocumentKind::TaxInvoice]), []];
    }

    /**
     * @param list<string> $kept
     */
    #[DataProvider('typedDrafts')]
    public function testARequestToReadAgainKeepsWhatAPersonTyped(ReceivedDocumentContent $content, array $kept): void
    {
        $document = $this->draft();
        $document->saveDraft($content);

        $applied = $this->application()->apply($document, $this->reading(), $document->version(), true);

        self::assertSame($kept, $this->keys($applied->suggested));
        $expected = array_values(array_filter(self::APPLICABLE, static fn (string $key): bool => !\in_array($key, $kept, true)));
        self::assertSame($expected, $this->keys($applied->applied));
        if ([] !== $kept) {
            self::assertSame([ReceivedDocumentWarning::READING_PARTLY_APPLIED], $applied->warnings);
        }
    }

    public function testWithoutTheRequestEverythingIsTakenOverWhatWasTyped(): void
    {
        $document = $this->draft();
        $document->saveDraft($this->blankWith(supplierNumber: 'TYPED-1'));

        $applied = $this->application()->apply($document, $this->reading(), $document->version());

        self::assertSame(self::APPLICABLE, $this->keys($applied->applied));
        self::assertSame('FV-2026-77', $document->supplierNumber());
    }

    private function application(?SupplierMatch $match = null): ReadingApplication
    {
        $match ??= SupplierMatch::none();

        return new ReadingApplication(new NoDocumentLock(), new readonly class($match) implements SupplierMatching {
            public function __construct(private SupplierMatch $match)
            {
            }

            public function match(Reading $reading): SupplierMatch
            {
                return $this->match;
            }
        });
    }

    private function party(): Party
    {
        return new Party(PartyKind::Company, 'Supplier Example s.r.o.', new RegistrationNumber('CZ', '87654321'), null, '');
    }

    private function matched(Party $party): SupplierMatch
    {
        return new SupplierMatch($party, null, SupplierSnapshot::of($party, new Address('Example Street 2', 'Example City', '110 00', 'CZ')));
    }

    private function notFound(): SupplierMatch
    {
        return new SupplierMatch(null, ReceivedDocumentWarning::SUPPLIER_NOT_FOUND);
    }

    /**
     * @param list<array{rate: string, baseMinor: int, vatMinor: int}>|null $recap
     * @param list<ReadValue>                                               $extra
     */
    private function reading(string $kind = 'tax_invoice', int $total = 12_100, ?array $recap = null, string $currency = 'CZK', array $extra = []): Reading
    {
        return new Reading([
            ...$extra,
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
     * A draft with only what is given typed.
     *
     * @param array{kind?: ReceivedDocumentKind, supplierNumber?: string, variableSymbol?: string, issueDate?: \DateTimeImmutable, taxPointDate?: \DateTimeImmutable, dueDate?: \DateTimeImmutable, total?: Money, vatRecap?: list<VatRecapLine>} $changes
     */
    private static function typed(array $changes): ReceivedDocumentContent
    {
        $blank = ReceivedDocumentContent::blank(new \DateTimeImmutable('2026-11-02'));

        return new ReceivedDocumentContent(
            $changes['kind'] ?? $blank->kind,
            $blank->treatment,
            null,
            SupplierSnapshot::none(),
            $changes['supplierNumber'] ?? '',
            $changes['variableSymbol'] ?? '',
            $changes['issueDate'] ?? null,
            $changes['taxPointDate'] ?? null,
            $changes['dueDate'] ?? null,
            $blank->receivedDate,
            Currency::CZK,
            $changes['total'] ?? null,
            null,
            null,
            $changes['vatRecap'] ?? [],
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
