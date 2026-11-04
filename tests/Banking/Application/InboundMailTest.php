<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Banking\Application;

use App\Banking\Application\InboundMail;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InboundMailTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, ?string, ?string}>
     */
    public static function senders(): iterable
    {
        yield 'plain' => ['notices@bank.example.com', 'notices@bank.example.com', 'bank.example.com'];
        yield 'mixed case and spaces' => [' Notices@Bank.Example.COM ', 'notices@bank.example.com', 'bank.example.com'];
        yield 'quoted local part with an at sign' => ['"a@b"@bank.example.com', '"a@b"@bank.example.com', 'bank.example.com'];
        yield 'no domain' => ['notices@', 'notices@', null];
        yield 'no at sign' => ['notices', 'notices', null];
        yield 'blank' => ['  ', null, null];
        yield 'none' => [null, null, null];
    }

    #[DataProvider('senders')]
    public function testTheSenderIsLowercasedAndSplit(?string $from, ?string $address, ?string $domain): void
    {
        $mail = $this->mail(from: $from);

        self::assertSame($address, $mail->from());
        self::assertSame($domain, $mail->fromDomain());
    }

    public function testHeadersAreMatchedByNameInTheirOrder(): void
    {
        $mail = $this->mail(headers: [
            ['Authentication-Results', 'mx.example.com; dkim=pass'],
            ['Received', 'from relay.example.net'],
            ['authentication-results', 'relay.example.net; dkim=fail'],
        ]);

        self::assertSame(['mx.example.com; dkim=pass', 'relay.example.net; dkim=fail'], $mail->headers('AUTHENTICATION-RESULTS'));
        self::assertSame(['from relay.example.net'], $mail->headers('received'));
        self::assertSame([], $mail->headers('Authentication'));
    }

    /**
     * @param list<array{string, string}> $headers
     */
    private function mail(?string $from = null, array $headers = []): InboundMail
    {
        return new InboundMail(
            headers: $headers,
            from: $from,
            subject: '',
            messageId: null,
            date: null,
            text: null,
            html: null,
            attachmentNames: [],
            sha256: hash('sha256', ''),
            size: 0,
        );
    }
}
