<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Banking\Application;

use App\Banking\Application\InboundAddresses;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Only the owner sees an inbound address, so every other view masks its token,
 * however the mail wrote the address: plainly, in a link, or wrapped.
 */
final class InboundAddressesTest extends TestCase
{
    private const string TOKEN = 'abcdefghijklmnopqrstuvwxyz';

    /**
     * @return iterable<string, array{string}>
     */
    public static function writtenAddresses(): iterable
    {
        yield 'plain' => ['Confirm forwarding to bank+'.self::TOKEN.'@example.com.'];
        yield 'upper case' => ['Confirm forwarding to BANK+'.strtoupper(self::TOKEN).'@EXAMPLE.COM.'];
        yield 'URL-encoded in a link' => ['https://mail.example.org/confirm?to=bank%2B'.self::TOKEN.'%40example.com&x=1'];
        yield 'URL-encoded in lower case' => ['https://mail.example.org/confirm?to=bank%2b'.self::TOKEN.'%40example.com'];
        yield 'wrapped by a quoted-printable soft break' => ["Confirm forwarding to bank+abcdefghijklm=\r\nnopqrstuvwxyz@example.com."];
        yield 'wrapped in an encoded link' => ["https://mail.example.org/confirm?to=bank%2Babcdefghij=\nklmnopqrstuvwxyz%40example.com"];
    }

    #[DataProvider('writtenAddresses')]
    public function testTheTokenIsMaskedHoweverTheAddressIsWritten(string $text): void
    {
        $masked = new InboundAddresses('bank+{token}@example.com')->masked($text);

        self::assertStringNotContainsStringIgnoringCase(self::TOKEN, $masked);
        self::assertStringNotContainsStringIgnoringCase('abcdefghijklm', $masked);
        self::assertStringContainsString("\u{2026}", $masked);
    }

    public function testOtherTextStaysAsItIs(): void
    {
        self::assertSame('Pay 100 CZK to 19-2000145399/0800.', new InboundAddresses('bank+{token}@example.com')->masked('Pay 100 CZK to 19-2000145399/0800.'));
    }
}
