<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Purchasing\Integration\Reading;

use Foresi\Purchasing\Application\PurchasingNotAllowed;
use Foresi\Purchasing\Application\Reading\AcceptReadValues;
use Foresi\Purchasing\Application\UnknownReceivedDocument;
use Foresi\Purchasing\Contract\Reading\ReadField;
use Foresi\Purchasing\Contract\Reading\Reading;
use Foresi\Purchasing\Contract\Reading\ReadValue;
use Foresi\Purchasing\Domain\DocumentReading;
use Foresi\Purchasing\Domain\ExchangeRate;
use Foresi\Purchasing\Domain\ReceivedDocumentKind;
use Foresi\Purchasing\Domain\ReceivedDocumentRefused;
use Foresi\Shared\Domain\Currency;
use Foresi\Tests\Purchasing\Application\PurchasingTestCase;

/**
 * A person takes values a reading left as suggestions; the reading here is
 * written by hand, so that every shape a stored reading can have is tried.
 */
final class AcceptReadValuesTest extends PurchasingTestCase
{
    public function testWhatDoesNotFitTheDraftIsNotTaken(): void
    {
        $id = $this->typedDraft();
        $before = $this->document($id);
        $this->readingOf($id, [
            new ReadValue(ReadField::IssueDate, '2026-13-45', 'isdoc'),
            new ReadValue(ReadField::Kind, 'bogus', 'isdoc'),
            new ReadValue(ReadField::Currency, 'XXX', 'isdoc'),
            new ReadValue(ReadField::Total, 5_000, 'isdoc'),
            new ReadValue(ReadField::BankAccount, 'CZ6508000000192000145399', 'payment_qr'),
        ]);

        $this->accepting()->accept($id, [ReadField::IssueDate, ReadField::Kind, ReadField::Total, ReadField::Total, ReadField::BankAccount, ReadField::SupplierName], $before->version());

        $document = $this->document($id);
        self::assertSame($before->version() + 1, $document->version());
        self::assertSame('2026-10-28', $document->issueDate()?->format('Y-m-d'));
        self::assertSame(ReceivedDocumentKind::TaxInvoice, $document->kind());
        self::assertSame(Currency::CZK, $document->currency());
        self::assertSame(121_000, $document->total()?->minor());
        self::assertSame($this->supplier->id(), $document->supplierParty()?->id(), 'A supplier the reading does not name stays.');
    }

    public function testEveryKindOfFieldIsTakenAsRead(): void
    {
        $id = $this->typedDraft();
        $this->readingOf($id, [
            new ReadValue(ReadField::Kind, 'credit_note', 'isdoc'),
            new ReadValue(ReadField::SupplierNumber, 'FV-ACCEPTED', 'isdoc'),
            new ReadValue(ReadField::VariableSymbol, '555', 'isdoc'),
            new ReadValue(ReadField::IssueDate, '2026-10-01', 'isdoc'),
            new ReadValue(ReadField::TaxPointDate, '2026-10-02', 'isdoc'),
            new ReadValue(ReadField::DueDate, '2026-10-30', 'isdoc'),
            new ReadValue(ReadField::Currency, 'EUR', 'isdoc'),
            new ReadValue(ReadField::Total, 12_100, 'isdoc'),
            new ReadValue(ReadField::VatRecap, [['rate' => '21', 'baseMinor' => 10_000, 'vatMinor' => 2_100]], 'isdoc'),
        ]);

        $this->accepting()->accept($id, [ReadField::Kind, ReadField::SupplierNumber, ReadField::VariableSymbol, ReadField::IssueDate, ReadField::TaxPointDate, ReadField::DueDate, ReadField::Currency, ReadField::Total, ReadField::VatRecap], $this->document($id)->version());

        $document = $this->document($id);
        self::assertSame(ReceivedDocumentKind::CreditNote, $document->kind());
        self::assertSame('FV-ACCEPTED', $document->supplierNumber());
        self::assertSame('555', $document->variableSymbol());
        self::assertSame('2026-10-01', $document->issueDate()?->format('Y-m-d'));
        self::assertSame('2026-10-02', $document->taxPointDate()?->format('Y-m-d'));
        self::assertSame('2026-10-30', $document->dueDate()?->format('Y-m-d'));
        self::assertSame(Currency::EUR, $document->currency());
        self::assertSame(-12_100, $document->total()?->minor());
        self::assertSame(-10_000, $document->base()->minor());
        self::assertNull($document->exchangeRate(), 'The rate of another currency does not carry over.');
    }

    public function testTheValuesNotChosenStayAsTheDraftHasThem(): void
    {
        $id = $this->typedDraft(currency: Currency::EUR, rate: ExchangeRate::of('25'));
        $this->readingOf($id, [
            new ReadValue(ReadField::IssueDate, '2026-10-01', 'isdoc'),
            new ReadValue(ReadField::Total, 99_900, 'isdoc'),
            new ReadValue(ReadField::VatRecap, [['rate' => '21', 'baseMinor' => 82_562, 'vatMinor' => 17_338]], 'isdoc'),
        ]);
        $before = $this->document($id);
        $recap = $before->vatRecap()->rows();
        self::assertNotSame([], $recap);

        $this->accepting()->accept($id, [ReadField::IssueDate], $before->version());

        $document = $this->document($id);
        self::assertSame('2026-10-01', $document->issueDate()?->format('Y-m-d'));
        self::assertSame($before->total()?->minor(), $document->total()?->minor());
        self::assertCount(\count($recap), $document->vatRecap()->rows());
        self::assertSame($recap[0]->base->minor(), $document->vatRecap()->rows()[0]->base->minor());
        self::assertSame('25', $document->exchangeRate()?->toString(), 'The currency did not change, so the rate stays.');
    }

    public function testTakingTheSupplierTakesTheContactTheReadingMatches(): void
    {
        $id = $this->typedDraft(supplier: null);
        $this->readingOf($id, [
            new ReadValue(ReadField::SupplierName, 'Supplier Example s.r.o.', 'isdoc'),
            new ReadValue(ReadField::SupplierRegistrationNumber, '87654321', 'isdoc'),
            new ReadValue(ReadField::SupplierCountry, 'CZ', 'isdoc'),
        ]);
        $before = $this->document($id);
        self::assertNull($before->supplierParty());

        $this->accepting()->accept($id, [ReadField::SupplierName], $before->version());

        $after = $this->document($id);
        self::assertNotNull($after->supplierParty());
        self::assertSame($this->supplier->id(), $after->supplierParty()->id());
    }

    public function testTakingTheSupplierWhenNobodyMatchesIsRefused(): void
    {
        $id = $this->typedDraft(supplier: null);
        $this->readingOf($id, [new ReadValue(ReadField::SupplierName, 'Nobody Known s.r.o.', 'isdoc')]);
        $version = $this->document($id)->version();

        $this->assertRefused(ReceivedDocumentRefused::SUPPLIER_UNKNOWN, fn (): \Foresi\Purchasing\Application\SavedReceivedDocument => $this->accepting()->accept($id, [ReadField::SupplierName], $version));
    }

    public function testAnAmountCanBeTakenWhileTheDraftHasNoSupplier(): void
    {
        $id = $this->typedDraft(supplier: null);
        $this->readingOf($id, [new ReadValue(ReadField::VariableSymbol, '777', 'isdoc')]);

        $this->accepting()->accept($id, [ReadField::VariableSymbol], $this->document($id)->version());

        self::assertSame('777', $this->document($id)->variableSymbol());
        self::assertNull($this->document($id)->supplierParty());
    }

    public function testAnUnknownDocumentIsUnknown(): void
    {
        $this->expectException(UnknownReceivedDocument::class);

        $this->accepting()->accept('0190b4a1-0000-7000-8000-000000000000', [ReadField::Total], 1);
    }

    public function testAGuestIsRefusedBeforeAnythingIsLookedUp(): void
    {
        $this->signIn($this->guest);

        $this->expectException(PurchasingNotAllowed::class);
        $this->accepting()->accept('0190b4a1-0000-7000-8000-000000000000', [ReadField::Total], 1);
    }

    private function accepting(): AcceptReadValues
    {
        return self::getContainer()->get(AcceptReadValues::class);
    }

    /**
     * An uploaded draft a person filled in.
     */
    private function typedDraft(?string $supplier = 'supplier', Currency $currency = Currency::CZK, ?ExchangeRate $rate = null): string
    {
        $id = $this->draft();
        $kind = Currency::CZK === $currency ? ReceivedDocumentKind::TaxInvoice : ReceivedDocumentKind::SimplifiedReceipt;
        $this->editing()->edit($id, $this->input(kind: $kind, supplierId: $supplier, currency: $currency, rate: $rate));

        return $id;
    }

    /**
     * The reading of the document as written by hand.
     *
     * @param list<ReadValue> $values
     */
    private function readingOf(string $id, array $values): void
    {
        $reading = $this->em->find(DocumentReading::class, $id) ?? throw new \LogicException('No reading.');
        $reading->finish(new Reading($values), [], [], [], new \DateTimeImmutable('2026-11-10 09:00:00'));
        $this->em->flush();
        $this->em->clear();
    }
}
