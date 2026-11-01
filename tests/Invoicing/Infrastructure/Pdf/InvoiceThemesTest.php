<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Infrastructure\Pdf;

use App\Invoicing\Application\InvoicePdf;
use App\Invoicing\Application\InvoicingCountries;
use App\Invoicing\Application\IssuedDocument;
use App\Invoicing\Application\IssuedDocumentFactory;
use App\Invoicing\Application\LineInput;
use App\Invoicing\Domain\Percent;
use App\Invoicing\Domain\PriceType;
use App\Invoicing\Domain\Quantity;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\VatRateKind;
use App\Invoicing\Infrastructure\Form\AmountText;
use App\Invoicing\Infrastructure\Pdf\InvoiceColumns;
use App\Invoicing\Infrastructure\Pdf\InvoiceTheme;
use App\Invoicing\Infrastructure\Pdf\PdfEngine;
use App\Invoicing\Infrastructure\Pdf\TcpdfInvoicePdf;
use App\Party\Application\FirmImages;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use App\Tenancy\Application\TenantContext;
use App\Tests\Invoicing\Application\InvoiceTestCase;
use App\Tests\Support\PdfTools;
use App\Tests\Support\VeraPdf;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * What must hold for every theme: the same fields, whole values, a line under each
 * page and a valid PDF/A-3a. The rest of the printed form is proved in InvoicePdfTest.
 */
final class InvoiceThemesTest extends InvoiceTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function themes(): iterable
    {
        yield 'statement' => [InvoiceTheme::STATEMENT];
        yield 'panel' => [InvoiceTheme::PANEL];
        yield 'slip' => [InvoiceTheme::SLIP];
    }

    #[DataProvider('themes')]
    public function testEveryFieldIsThere(string $theme): void
    {
        $document = $this->issued([$this->line('1260.00', text: 'Consulting work'), $this->line('0.00', text: 'Second')], ['orderNumber' => 'PO-77']);
        $text = $this->textOf($theme, $document);

        foreach ([
            'Faktura - daňový doklad',
            'Example Firm s.r.o.', 'Example Street 1', '100 00', 'Example City', 'IČO: 12345695', 'DIČ: CZ12345695', 'Example register, entry C 1',
            'Example Customer a.s.', 'Customer Street 2', '200 00', 'Customer City', 'IČO: 87654320', 'DIČ: CZ87654320',
            'Datum vystavení', '1. 11. 2026', 'Datum zdanitelného plnění', 'Datum splatnosti', '15. 11. 2026',
            'Consulting work', 'bez DPH', 'Sazba', 'Základ', 'DPH',
            '1 525,00 CZK', 'CZ6508000000192000145399', '19-2000145399/0800',
            'Variabilní symbol', '20260001', 'Konstantní symbol', '0308',
            'Způsob platby', 'PO-77', 'Before', 'After', 'Consulting', 'Razítko a podpis', 'QR Platba+F',
        ] as $expected) {
            self::assertStringContainsString($expected, $text);
        }
        self::assertMatchesRegularExpression('/Zaokrouhlení\s+1,00\s+0,40\s+0\s+0,40\s+0,00\s+0,40/', $text);
        self::assertSame(1, substr_count($text, 'Zaokrouhlení'));
        self::assertMatchesRegularExpression('/21 %\s+1 260,00\s+264,60\s+1 524,60/', $text);
    }

    #[DataProvider('themes')]
    public function testReverseChargeKeepsItsWords(string $theme): void
    {
        $text = $this->textOf($theme, $this->issued([$this->line(treatment: TaxTreatment::ReverseCharge, kind: null, supplyCode: '4')]));

        self::assertSame(1, substr_count($text, 'daň odvede zákazník'));
        self::assertMatchesRegularExpression('/Kód předmětu plnění: 4\b/', $text);
    }

    #[DataProvider('themes')]
    public function testLongValuesStayWhole(string $theme): void
    {
        $document = $this->issued([
            $this->value('Annual framework, a description long enough to wrap in its own column', '1234.56', 'měsíc', '99999999.99', '33.33'),
            $this->value('Second', '12', 'člověkoden', '1234567890.12', '12.5'),
            $this->value('Third', '3', 'měsíc', '0.37', '0'),
        ]);
        $rendered = $this->pdf($theme)->render($document)->bytes;
        $text = PdfTools::text($rendered);

        foreach (['82 308 115 191,77', '17 284 704 190,27', '99 592 819 382,04', '12 962 962 846,26', '15 685 185 043,97', '1 234 567 890,12', '95 271 078 039,14', '115 278 004 427,35', '115 278 004 427,00 CZK', '33,33 %', '12,5 %', 'člověkoden'] as $whole) {
            self::assertStringContainsString($whole, $text);
        }
        self::assertSame(2, substr_count($text, 'měsíc'));
        VeraPdf::assertConforms($rendered, '3a');
    }

    #[DataProvider('themes')]
    public function testSixtyLinesCarryTheDocumentNumberOnEachPageAndConform(string $theme): void
    {
        $lines = [];
        for ($index = 1; $index <= 60; ++$index) {
            $lines[] = $this->line(text: str_repeat('Příliš žluťoučký kůň úpěl ďábelské ódy ', 3).'závěr'.$index);
        }
        $document = $this->issued($lines);
        $rendered = $this->pdf($theme)->render($document)->bytes;
        $text = PdfTools::text($rendered);
        $pages = PdfTools::pages($rendered);

        self::assertGreaterThan(1, $pages);
        for ($index = 1; $index <= 60; ++$index) {
            self::assertMatchesRegularExpression('/závěr'.$index.'\b/u', $text);
        }
        self::assertSame($pages, substr_count($text, 'Označení dodávky'));
        for ($page = 1; $page <= $pages; ++$page) {
            self::assertStringContainsString('Faktura '.$document->number.', strana '.$page.' z '.$pages, $text);
        }
        VeraPdf::assertConforms($rendered, '3a');
    }

    #[DataProvider('themes')]
    public function testASinglePageHasItsLineToo(string $theme): void
    {
        $document = $this->issued([$this->line()]);

        self::assertStringContainsString('Faktura '.$document->number.', strana 1 z 1', $this->textOf($theme, $document));
    }

    #[DataProvider('themes')]
    public function testACashDocumentHasNoCodeAndConforms(string $theme): void
    {
        $rendered = $this->pdf($theme)->render($this->issued([$this->line()], ['paymentMethod' => \App\Party\Domain\PaymentMethod::Cash, 'bankAccount' => null]))->bytes;

        self::assertStringNotContainsString('QR Platba+F', PdfTools::text($rendered));
        VeraPdf::assertConforms($rendered, '3a');
    }

    public function testAnUnknownThemeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        InvoiceTheme::named('none');
    }

    public function testAColumnGrowsToItsLongestValueAndTheTextColumnPaysForIt(): void
    {
        $fit = InvoiceColumns::fit(['total' => 10], ['total' => ['Celkem', '99 592 819 382,04']], 180.0, 2.9, 8.0);

        self::assertSame(8.0, $fit['font']);
        self::assertGreaterThan(10.0, (float) $fit['widths']['total']);
        self::assertEqualsWithDelta(100.0, (float) $fit['widths']['total'] + (float) $fit['widths']['text'], 0.02);
    }

    public function testATableThatCannotFitGetsASmallerType(): void
    {
        $long = ['123 456 789 012,00'];
        $fit = InvoiceColumns::fit(['a' => 5, 'b' => 5, 'c' => 5, 'd' => 5, 'e' => 5], ['a' => $long, 'b' => $long, 'c' => $long, 'd' => $long, 'e' => $long], 180.0, 2.9, 8.0);

        self::assertLessThan(8.0, $fit['font']);
    }

    private function value(string $text, string $quantity, string $unit, string $price, string $discount): LineInput
    {
        return new LineInput($text, Quantity::fromString($quantity), $unit, Money::of($price, Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString($discount));
    }

    /**
     * @param list<LineInput>      $lines
     * @param array<string, mixed> $overrides
     */
    private function issued(array $lines, array $overrides = []): IssuedDocument
    {
        return self::getContainer()->get(IssuedDocumentFactory::class)->of($this->issue->issue($this->input($lines, $overrides))->id());
    }

    private function textOf(string $theme, IssuedDocument $document): string
    {
        return PdfTools::text($this->pdf($theme)->render($document)->bytes);
    }

    private function pdf(string $theme): InvoicePdf
    {
        $container = self::getContainer();

        return new TcpdfInvoicePdf(
            $container->get(Environment::class),
            $container->get(PdfEngine::class),
            $container->get(InvoicingCountries::class),
            $container->get(FirmImages::class),
            $container->get(TenantContext::class),
            $container->get(LocaleSwitcher::class),
            $container->get(TranslatorInterface::class),
            $container->get(AmountText::class),
            $theme,
        );
    }
}
