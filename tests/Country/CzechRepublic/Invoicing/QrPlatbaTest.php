<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Country\CzechRepublic\Invoicing;

use App\Country\CzechRepublic\Invoicing\CzechVatRates;
use App\Country\CzechRepublic\Invoicing\QrPlatba;
use App\Invoicing\Application\IssuedDocument;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\DocumentParty;
use App\Invoicing\Domain\Percent;
use App\Invoicing\Domain\RateSummary;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\TotalRounding;
use App\Invoicing\Domain\VatRegime;
use App\Party\Domain\Address;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\PaymentMethod;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use chillerlan\QRCode\QRCode;
use Com\Tecnick\Barcode\Barcode;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

final class QrPlatbaTest extends TestCase
{
    private const string IBAN = 'CZ6508000000192000145399';

    public function testThePaymentAndTheInvoicePartAreOneString(): void
    {
        self::assertSame(
            'SPD*1.0*ACC:CZ6508000000192000145399*AM:3751.00*CC:CZK*DT:20261114*MSG:20260001*X-VS:20260001'
            .'*X-INV:SID%2A1.0%2AID:20260001%2ADD:20261031%2ATP:0%2ATD:9%2AVII:CZ12345679%2AINI:12345679%2AVIR:CZ87654321%2AINR:87654321%2ADUZP:20261031%2ATB0:3100.00%2AT0:651.00',
            $this->qr(),
        );
    }

    public function testBaseAndReducedRateHaveTheirOwnKeys(): void
    {
        $code = $this->qr(summaries: [
            new RateSummary(TaxTreatment::Standard, Percent::fromString('21.00'), $this->money('100.00'), $this->money('21.00'), $this->money('121.00')),
            new RateSummary(TaxTreatment::Standard, Percent::fromString('12.00'), $this->money('200.00'), $this->money('24.00'), $this->money('224.00')),
        ]);

        self::assertStringContainsString('%2ATB0:100.00%2AT0:21.00%2ATB1:200.00%2AT1:24.00', (string) $code);
    }

    public function testAReducedRateAloneIsNotTheBaseRate(): void
    {
        $code = $this->qr(summaries: [new RateSummary(TaxTreatment::Standard, Percent::fromString('12.00'), $this->money('200.00'), $this->money('24.00'), $this->money('224.00'))]);

        self::assertStringContainsString('%2ATB1:200.00%2AT1:24.00', (string) $code);
        self::assertStringNotContainsString('TB0', (string) $code);
    }

    public function testAStarInTheNumberIsEncoded(): void
    {
        $code = $this->qr(number: 'FA*1');

        self::assertStringContainsString('*MSG:FA%2A1*', (string) $code);
        // Twice in the part that is encoded as a whole.
        self::assertStringContainsString('%2AID:FA%252A1%2ADD:', (string) $code);
    }

    public function testConstantSymbolGoesToXKs(): void
    {
        self::assertStringContainsString('*X-VS:20260001*X-KS:0308*X-INV:', (string) $this->qr(constant: '0308'));
    }

    public function testNoCodeForCash(): void
    {
        self::assertNull($this->qr(method: PaymentMethod::Cash));
        self::assertNull($this->qr(method: PaymentMethod::Card));
    }

    public function testNoCodeForZero(): void
    {
        self::assertNull($this->qr(payable: '0.00'));
    }

    public function testNoCodeForANegativeAmount(): void
    {
        self::assertNull($this->qr(payable: '-1.00'));
    }

    public function testNoCodeAboveTheLimit(): void
    {
        self::assertNull($this->qr(payable: '10000000.00'));
        self::assertNotNull($this->qr(payable: '9999999.99'));
    }

    public function testNoCodeWithoutAnAccount(): void
    {
        self::assertNull($this->qr(iban: null));
        self::assertNull($this->qr(iban: ''));
    }

    public function testALongVariableSymbolIsLeftOutNotCut(): void
    {
        $code = (string) $this->qr(vs: '12345678901');

        self::assertStringStartsWith('SPD*1.0*', $code);
        self::assertStringNotContainsString('X-VS', $code);
        self::assertStringNotContainsString('1234567890', $code);
        self::assertStringNotContainsString('X-VS', (string) $this->qr(vs: 'ABC'));
    }

    public function testNoCodeInForeignCurrency(): void
    {
        self::assertNull($this->qr(currency: Currency::EUR));
    }

    public function testReverseChargeSetsTp1AndNtb(): void
    {
        $code = (string) $this->qr(summaries: [new RateSummary(TaxTreatment::ReverseCharge, null, $this->money('5000.00'), $this->money('0.00'), $this->money('5000.00'))]);

        self::assertStringContainsString('%2ATP:1%2ATD:9', $code);
        self::assertStringContainsString('%2ANTB:5000.00', $code);
        self::assertStringNotContainsString('TB0', $code);
    }

    public function testAMixOfSuppliesSetsTp2(): void
    {
        $code = (string) $this->qr(summaries: [
            new RateSummary(TaxTreatment::Standard, Percent::fromString('21.00'), $this->money('100.00'), $this->money('21.00'), $this->money('121.00')),
            new RateSummary(TaxTreatment::ReverseCharge, null, $this->money('5000.00'), $this->money('0.00'), $this->money('5000.00')),
            new RateSummary(TaxTreatment::Exempt, null, $this->money('50.00'), $this->money('0.00'), $this->money('50.00')),
        ]);

        self::assertStringContainsString('%2ATP:2%2A', $code);
        self::assertStringContainsString('%2ANTB:5050.00', $code);
    }

    public function testANonPayerHasTd0AndNoVatKeys(): void
    {
        $code = (string) $this->qr(regime: VatRegime::NonPayer, summaries: [new RateSummary(TaxTreatment::NotApplicable, null, $this->money('3751.00'), $this->money('0.00'), $this->money('3751.00'))]);

        self::assertStringContainsString('%2ATP:0%2ATD:0', $code);
        self::assertStringContainsString('%2AINI:12345679%2AINR:87654321', $code);
        foreach (['VII:', 'VIR:', 'DUZP:', 'TB0:', 'T0:', 'NTB:'] as $key) {
            self::assertStringNotContainsString($key, $code);
        }
    }

    public function testTheOrderNumberHasItsKey(): void
    {
        self::assertStringContainsString('%2ATD:9%2AON:PO-77%2AVII:', (string) $this->qr(order: 'PO-77'));
    }

    public function testAnOverLongOrderNumberLeavesOnKeyOut(): void
    {
        self::assertStringNotContainsString('%2AON:', (string) $this->qr(order: str_repeat('1', 21)));
        self::assertStringContainsString('%2AON:'.str_repeat('1', 20).'%2A', (string) $this->qr(order: str_repeat('1', 20)));
    }

    public function testAnOverLongCustomerNumberLeavesItsKeyOut(): void
    {
        self::assertStringNotContainsString('INR:', (string) $this->qr(customerNumber: '123456789'));
    }

    public function testAnOverLongNumberLeavesItsKeysOutNotCut(): void
    {
        $number = str_repeat('1', 61);
        $code = (string) $this->qr(number: $number);

        self::assertStringNotContainsString('MSG:', $code);
        self::assertStringNotContainsString('%2AID:', $code);
        self::assertStringContainsString('*X-INV:SID%2A1.0%2ADD:', $code);
    }

    /**
     * What a phone reads from the drawn modules is what was written.
     */
    #[RequiresPhpExtension('gd')]
    public function testADrawnCodeDecodesToTheSameString(): void
    {
        $text = (string) $this->qr(constant: '0308', order: 'PO-77');

        self::assertSame($text, new QRCode()->readFromBlob($this->png($text))->data);
    }

    private function png(string $text): string
    {
        $grid = new Barcode()->getBarcodeObj('QRCODE,M', $text)->getGridArray();
        $module = 6;
        $quiet = 4 * $module;
        $side = \count($grid) * $module + 2 * $quiet;
        $image = imagecreatetruecolor($side, $side);
        self::assertNotFalse($image);
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        self::assertNotFalse($white);
        self::assertNotFalse($black);
        imagefill($image, 0, 0, $white);
        foreach ($grid as $row => $cells) {
            foreach ($cells as $column => $cell) {
                if ('1' === $cell) {
                    imagefilledrectangle($image, $quiet + $column * $module, $quiet + $row * $module, $quiet + ($column + 1) * $module - 1, $quiet + ($row + 1) * $module - 1, $black);
                }
            }
        }
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /**
     * @param list<RateSummary>|null $summaries
     */
    private function qr(
        ?string $iban = self::IBAN,
        string $payable = '3751.00',
        string $due = '2026-11-14',
        string $number = '20260001',
        string $vs = '20260001',
        string $constant = '',
        string $order = '',
        string $customerNumber = '87654321',
        PaymentMethod $method = PaymentMethod::BankTransfer,
        Currency $currency = Currency::CZK,
        VatRegime $regime = VatRegime::Payer,
        ?array $summaries = null,
    ): ?string {
        $money = fn (string $amount): Money => Money::of($amount, $currency);
        $summaries ??= [new RateSummary(TaxTreatment::Standard, Percent::fromString('21.00'), $money('3100.00'), $money('651.00'), $money('3751.00'))];

        return new QrPlatba(new CzechVatRates())->for(new IssuedDocument(
            '019a0000-0000-7000-8000-000000000001',
            DocumentKind::Invoice,
            $number,
            $regime,
            TotalRounding::WholeUnit,
            $currency,
            '1',
            $this->party('Customer', $customerNumber, 'CZ87654321'),
            $this->party('Issuer', '12345679', 'CZ12345679'),
            new \DateTimeImmutable('2026-10-31'),
            new \DateTimeImmutable('2026-10-31'),
            new \DateTimeImmutable($due),
            $method,
            $iban,
            $vs,
            $constant,
            $order,
            '',
            '',
            '',
            DocumentLanguage::Czech,
            [],
            $summaries,
            $money('3100.00'),
            $money('651.00'),
            $money('3751.00'),
            $money('0.00'),
            $money($payable),
        ));
    }

    private function party(string $name, string $number, string $vatId): DocumentParty
    {
        return new DocumentParty($name, $number, $vatId, new Address('Street 1', 'City', '100 00', 'CZ'), '', null, '', '', null, null);
    }

    private function money(string $amount): Money
    {
        return Money::of($amount, Currency::CZK);
    }
}
