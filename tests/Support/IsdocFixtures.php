<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Support;

use Foresi\Country\Integration\Invoicing\CzechRepublic\IsdocWriter;
use Foresi\Invoicing\Contract\CorrectedDocument;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Contract\DocumentLine;
use Foresi\Invoicing\Contract\DocumentParty;
use Foresi\Invoicing\Contract\IssuedDocument;
use Foresi\Invoicing\Contract\Percent;
use Foresi\Invoicing\Contract\PriceType;
use Foresi\Invoicing\Contract\Quantity;
use Foresi\Invoicing\Contract\TaxTreatment;
use Foresi\Invoicing\Contract\TotalRounding;
use Foresi\Invoicing\Contract\VatRegime;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\DocumentLanguage;
use Foresi\Party\Domain\PaymentMethod;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;

/**
 * Writes made-up ISDOC files with the repository's own writer, so the tests of
 * the reader read what the writer writes. Nothing here is a real document.
 */
final class IsdocFixtures
{
    public const string IBAN = 'CZ6508000000192000145399';

    /**
     * An invoice of "Cloud Host s.r.o.": 10 000 CZK without VAT at 21 %, 12 100 CZK to pay.
     */
    public static function invoice(): string
    {
        return new IsdocWriter()->write(self::document(1_000_000, 210_000));
    }

    /**
     * The same invoice as a credit note: a negative payable, written in positive amounts.
     */
    public static function creditNote(): string
    {
        return new IsdocWriter()->write(self::document(-1_000_000, -210_000, creditNote: true));
    }

    private static function document(int $netMinor, int $vatMinor, bool $creditNote = false): IssuedDocument
    {
        $money = static fn (int $minor): Money => Money::ofMinor($minor, Currency::CZK);
        $gross = $netMinor + $vatMinor;
        $line = self::line($money($netMinor), $money($vatMinor), $money($gross));
        $original = $creditNote ? new CorrectedDocument('019a0000-0000-7000-8000-000000000009', DocumentKind::Invoice, '20260001', new \DateTimeImmutable('2026-10-01')) : null;

        return new IssuedDocument(
            '019a0000-0000-7000-8000-000000000001',
            $creditNote ? DocumentKind::CorrectionDocument : DocumentKind::Invoice,
            $creditNote ? '20260002' : '20260001',
            VatRegime::Payer,
            TotalRounding::None,
            Currency::CZK,
            '1',
            self::party('Example Customer a.s.', '87654321', 'CZ87654321'),
            self::party('Cloud Host s.r.o.', '12345678', 'CZ12345678'),
            new \DateTimeImmutable('2026-10-31'),
            new \DateTimeImmutable('2026-10-30'),
            new \DateTimeImmutable('2026-11-14'),
            PaymentMethod::BankTransfer,
            self::IBAN,
            '2026100',
            '',
            '',
            '',
            '',
            '',
            DocumentLanguage::English,
            [$line],
            [],
            $money($netMinor),
            $money($vatMinor),
            $money($gross),
            $money(0),
            $money($gross),
            corrected: $original,
        );
    }

    private static function party(string $name, string $registrationNumber, string $vatId): DocumentParty
    {
        return new DocumentParty($name, $registrationNumber, $vatId, new Address('Example Street 1', 'Prague', '110 00', 'CZ'), '', null, '', '', null, null);
    }

    private static function line(Money $net, Money $vat, Money $gross): DocumentLine
    {
        return new class($net, $vat, $gross) implements DocumentLine {
            public function __construct(private readonly Money $net, private readonly Money $vat, private readonly Money $gross)
            {
            }

            public function deducts(): ?string
            {
                return null;
            }

            public function text(): string
            {
                return 'Hosting';
            }

            public function quantity(): Quantity
            {
                return Quantity::fromString('1');
            }

            public function unit(): string
            {
                return '';
            }

            public function price(): Money
            {
                return $this->net;
            }

            public function priceType(): PriceType
            {
                return PriceType::WithoutVat;
            }

            public function treatment(): TaxTreatment
            {
                return TaxTreatment::Standard;
            }

            public function supplyCode(): ?string
            {
                return null;
            }

            public function rate(): Percent
            {
                return Percent::fromString('21');
            }

            public function discount(): Percent
            {
                return Percent::fromString('0');
            }

            public function unitNet(): Money
            {
                return $this->net;
            }

            public function net(): Money
            {
                return $this->net;
            }

            public function vat(): Money
            {
                return $this->vat;
            }

            public function gross(): Money
            {
                return $this->gross;
            }
        };
    }
}
