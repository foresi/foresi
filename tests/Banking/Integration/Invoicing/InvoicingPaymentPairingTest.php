<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Banking\Integration\Invoicing;

use Foresi\Banking\Application\PairingRefused;
use Foresi\Banking\Integration\Invoicing\InvoicingPaymentPairing;
use Foresi\Invoicing\Contract\BankPaymentRefused;
use Foresi\Invoicing\Contract\BankPayments;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class InvoicingPaymentPairingTest extends TestCase
{
    public function testEveryInvoiceOfThePairingIsLocked(): void
    {
        $locked = [];
        $payments = self::createStub(BankPayments::class);
        $payments->method('lock')->willReturnCallback(static function (string $id) use (&$locked): void {
            $locked[] = $id;
        });

        new InvoicingPaymentPairing($payments, self::createStub(UrlGeneratorInterface::class))->lock(['first', 'second']);

        self::assertSame(['first', 'second'], $locked);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusals(): iterable
    {
        yield 'unknown' => [BankPaymentRefused::UNKNOWN_INVOICE, PairingRefused::UNKNOWN_DOCUMENT];
        yield 'currency' => [BankPaymentRefused::CURRENCY_MISMATCH, PairingRefused::CURRENCY_MISMATCH];
        yield 'trashed' => [BankPaymentRefused::TRASHED, PairingRefused::DOCUMENT_TRASHED];
        yield 'other' => ['locked', PairingRefused::PAYMENT_REFUSED];
    }

    #[DataProvider('refusals')]
    public function testWhatInvoicingRefusesRefusesThePairing(string $invoicing, string $pairing): void
    {
        $payments = self::createStub(BankPayments::class);
        $payments->method('lock')->willThrowException(new BankPaymentRefused($invoicing, 'problem', ['number' => '2026-001']));

        try {
            new InvoicingPaymentPairing($payments, self::createStub(UrlGeneratorInterface::class))->lock(['first']);
            self::fail('The pairing is refused.');
        } catch (PairingRefused $refused) {
            self::assertSame($pairing, $refused->reason);
            if (PairingRefused::UNKNOWN_DOCUMENT !== $pairing) {
                self::assertSame(['problem', ['number' => '2026-001']], [$refused->problem, $refused->parameters]);
            }
        }
    }
}
