<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Country\CzechRepublic\Invoicing\CzechInvoicing;
use App\Invoicing\Application\InvoiceInput;
use App\Invoicing\Application\InvoicePdf;
use App\Invoicing\Application\IssuedDocumentFactory;
use App\Invoicing\Application\LineInput;
use App\Invoicing\Application\SendDraft;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\DocumentParty;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\InvoiceLine;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\TotalRounding;
use App\Invoicing\Domain\VatRateKind;
use App\Invoicing\Domain\VatRegime;
use App\Party\Domain\Address;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\VatStatus;
use App\Shared\Domain\Currency;
use App\Tests\Support\PdfTools;

/**
 * An advance invoice asks for payment before the supply: no tax document, so no
 * tax point and no ISDOC, with its rates taken on its issue date.
 */
final class AdvanceInvoiceTest extends InvoiceTestCase
{
    public function testAnAdvanceIsIssuedWithoutATaxPoint(): void
    {
        $advance = $this->issue->issue($this->advanceInput());

        $stored = $this->reloaded($advance);
        self::assertSame(DocumentKind::Advance, $stored->kind());
        self::assertNull($stored->taxPointDate());
        self::assertSame('2026-11-01', $stored->rateDate()->format('Y-m-d'));
        self::assertNull($this->em->getConnection()->fetchOne('SELECT tax_point_date FROM invoice WHERE id = ?', [$advance->id()]));
        self::assertSame('Z920260001', $stored->number());
        self::assertSame('920260001', $stored->variableSymbol());
    }

    public function testAnAdvanceNeedsNoTaxPointWhereAnInvoiceDoes(): void
    {
        $this->issue->issue($this->advanceInput(['taxPointDate' => null]));

        $this->assertRefusedFor('invoice.problem.tax_point_missing', fn (): mixed => $this->issue->issue($this->input(overrides: ['taxPointDate' => null])));
    }

    public function testAnAdvanceTakesTheRatesOfItsIssueDate(): void
    {
        // The tax point a form may still send is not what the rates are taken on.
        $advance = $this->issue->issue($this->advanceInput(['issueDate' => new \DateTimeImmutable('2026-12-30'), 'taxPointDate' => new \DateTimeImmutable('2027-02-01'), 'dueDate' => new \DateTimeImmutable('2027-01-15')]));

        $line = $this->store->lines($this->reloaded($advance))[0];
        self::assertSame('21.00', $line->rate()?->decimal());
        self::assertNull($this->reloaded($advance)->taxPointDate());
    }

    public function testAnAdvanceAtTheBaseTheReducedAndNoRate(): void
    {
        $advance = $this->issue->issue($this->advanceInput(lines: [
            $this->line('1000.00'),
            $this->line('1000.00', kind: VatRateKind::Reduced),
            $this->line('1000.00', treatment: TaxTreatment::Exempt, kind: null),
        ]));

        $stored = $this->reloaded($advance);
        self::assertSame(['21.00', '12.00', null], array_map(static fn (InvoiceLine $line): ?string => $line->rate()?->decimal(), $this->store->lines($stored)));
        self::assertSame(33000, $stored->vat()->minor());
        self::assertSame(333000, $stored->payable()->minor());
    }

    public function testEditingAnAdvanceKeepsItWithoutATaxPoint(): void
    {
        $advance = $this->issue->issue($this->advanceInput());

        $this->edited($advance, $this->advanceInput(['seriesId' => null, 'description' => 'Second milestone', 'taxPointDate' => new \DateTimeImmutable('2026-11-20')]));

        $stored = $this->reloaded($advance);
        self::assertSame('Second milestone', $stored->description());
        self::assertNull($stored->taxPointDate());
        self::assertSame(DocumentKind::Advance, $stored->kind());
    }

    public function testTheDocumentRefusesATaxPointOnAnAdvanceAndNoneOnAnInvoice(): void
    {
        $advance = $this->reloaded($this->issue->issue($this->advanceInput()));
        $copy = DocumentParty::ofCustomer($this->customer, new Address('Customer Street 2', 'Customer City', '200 00', 'CZ'), null);

        try {
            $advance->change($this->input()->change($this->customer, $copy, DocumentKind::Invoice), new \DateTimeImmutable('2026-11-06 12:00'));
            self::fail('Expected the advance to refuse a tax point.');
        } catch (\DomainException $refused) {
            self::assertSame('A tax document has a tax point, and an advance invoice has none.', $refused->getMessage());
        }

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageIsOrContains('A tax document has a tax point, and an advance invoice has none.');
        new Invoice($this->firm, $this->series, '20269999', VatRegime::Payer, TotalRounding::None, Currency::CZK, $copy, $this->input()->change($this->customer, $copy, DocumentKind::Advance), null, new \DateTimeImmutable('2026-11-01'));
    }

    public function testTheAdvancePdfIsTitledAndSaysItIsNoTaxDocument(): void
    {
        $document = self::getContainer()->get(IssuedDocumentFactory::class)->of($this->issue->issue($this->advanceInput())->id());

        $rendered = self::getContainer()->get(InvoicePdf::class)->render($document);
        $text = PdfTools::text($rendered->bytes);

        self::assertStringContainsString('Zálohová faktura', $text);
        self::assertStringNotContainsString('daňový doklad', str_replace('nejedná se o daňový doklad', '', $text));
        self::assertStringContainsString('Rekapitulace DPH je pouze informativní, nejedná se o daňový doklad.', $text);
        self::assertStringNotContainsString('Datum zdanitelného plnění', $text);
        self::assertStringContainsString('QR Platba+F', $text);
        self::assertSame('Z920260001.pdf', $rendered->fileName);
    }

    public function testAnEnglishAdvancePdfIsInEnglish(): void
    {
        $document = self::getContainer()->get(IssuedDocumentFactory::class)->of($this->issue->issue($this->advanceInput(['language' => DocumentLanguage::English]))->id());

        $text = PdfTools::text(self::getContainer()->get(InvoicePdf::class)->render($document)->bytes);

        self::assertStringContainsString('Advance invoice', $text);
        self::assertStringContainsString('VAT breakdown is informative only, this is not a tax document.', $text);
        self::assertStringNotContainsString('Tax point date', $text);
    }

    public function testANonPayerAdvanceHasNoRecapToExplain(): void
    {
        $firm = $this->firm(VatStatus::NotRegistered, null, registrationNumber: '12345687');
        $this->em->flush();
        $document = self::getContainer()->get(IssuedDocumentFactory::class)->of($this->issue->issue($this->advanceInput(
            ['firmId' => $firm->id()],
            [$this->line(treatment: TaxTreatment::NotApplicable, kind: null)],
        ))->id());

        $text = PdfTools::text(self::getContainer()->get(InvoicePdf::class)->render($document)->bytes);

        self::assertStringContainsString('Zálohová faktura', $text);
        self::assertStringNotContainsString('Rekapitulace DPH', $text);
    }

    public function testAnAdvanceHasNoIsdocAndItsQrNoTaxFields(): void
    {
        $document = self::getContainer()->get(IssuedDocumentFactory::class)->of($this->issue->issue($this->advanceInput())->id());
        $czech = self::getContainer()->get(CzechInvoicing::class);

        self::assertNull($czech->electronicFile($document));
        $code = (string) $czech->paymentCode($document);
        self::assertStringContainsString('AM:1210.00', $code);
        self::assertStringContainsString('X-VS:920260001', $code);
        self::assertStringContainsString('TD:0', $code);
        self::assertStringNotContainsString('TD:9', $code);
        self::assertStringNotContainsString('DUZP', $code);
        self::assertStringNotContainsString('TB0', $code);
        self::assertSame('Z920260001.pdf', self::getContainer()->get(InvoicePdf::class)->render($document)->fileName);
    }

    public function testAnAdvanceIsSentWithTheAdvanceTemplate(): void
    {
        $advance = $this->issue->issue($this->advanceInput());

        $draft = $this->drafts()->for($this->reloaded($advance));

        self::assertSame('Zálohová faktura Z920260001', $draft->subject);
        self::assertStringContainsString('zálohovou fakturu č. Z920260001', $draft->body);
    }

    public function testAnInvoiceIsStillSentWithTheInvoiceTemplate(): void
    {
        $draft = $this->drafts()->for($this->reloaded($this->issue->issue($this->input())));

        self::assertSame('Faktura 20260001', $draft->subject);
    }

    private function drafts(): SendDraft
    {
        return self::getContainer()->get(SendDraft::class);
    }

    /**
     * @param array<string, mixed>  $overrides named arguments of InvoiceInput
     * @param array<LineInput>|null $lines
     */
    private function advanceInput(array $overrides = [], ?array $lines = null): InvoiceInput
    {
        return $this->input($lines, [...['seriesId' => null, 'kind' => DocumentKind::Advance, 'variableSymbolFollowsNumber' => true, 'variableSymbol' => ''], ...$overrides]);
    }
}
