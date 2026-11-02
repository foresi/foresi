<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Banking\Infrastructure\Mime;

use App\Banking\Application\InboundMail;
use App\Banking\Infrastructure\Mime\ZbatesonMailReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ZbatesonMailReaderTest extends TestCase
{
    public function testHtmlOnlyQuotedPrintableUtf8IsDecoded(): void
    {
        $mail = $this->read('html-only-qp-utf8.eml');

        self::assertNull($mail->text());
        self::assertSame(
            "<html><body><p>Amount: 1\u{A0}234,50 CZK</p><p>Note: naïve café – déjà vu</p></body></html>\n",
            $mail->html(),
        );
        self::assertSame('Payment notice – café', $mail->subject());
        self::assertSame('html-only-1@bank.example.com', $mail->messageId());
        self::assertEquals(new \DateTimeImmutable('2026-11-02T09:15:00+01:00'), $mail->date());
    }

    public function testWindows1250TextIsDecodedToUtf8(): void
    {
        $mail = $this->read('windows-1250.eml');

        self::assertSame("Counter-party: Šimon Říha, Žďár\nAmount: 1\u{A0}234,50 CZK\n", $mail->text());
        self::assertNull($mail->html());
    }

    public function testMultipartAlternativeGivesBothParts(): void
    {
        $mail = $this->read('multipart-alternative.eml');

        self::assertSame("Amount: 1\u{A0}234,50 CZK\r\n", $mail->text());
        self::assertSame("<p>Amount: <b>1\u{A0}234,50 CZK</b></p>\r\n", $mail->html());
        self::assertSame([], $mail->attachmentNames());
    }

    public function testAttachmentNamesAreListedAndNotInText(): void
    {
        $mail = $this->read('attachment.eml');

        self::assertSame(['Oznameni.pdf'], $mail->attachmentNames());
        self::assertSame("Amount: 1 234,50 CZK\n", $mail->text());
        self::assertNull($mail->html());
    }

    public function testHeadersKeepOrderAndRepeats(): void
    {
        $mail = $this->read('auth-results.eml');

        self::assertSame([
            'mx.example.com; dkim=pass header.d=bank.example.com header.s=sel1; dmarc=pass header.from=bank.example.com',
            'relay.example.net; dkim=fail header.d=bank.example.com',
        ], $mail->headers('authentication-results'));
        self::assertSame($mail->headers('Authentication-Results'), $mail->headers('AUTHENTICATION-RESULTS'));
        self::assertSame([], $mail->headers('ARC-Authentication-Results'));
        self::assertSame(['bank+token@example.com'], $mail->headers('To'));
    }

    public function testFromWithDisplayNameGivesLowercaseAddress(): void
    {
        $mail = $this->read('auth-results.eml');

        self::assertSame('notices@bank.example.com', $mail->from());
        self::assertSame('bank.example.com', $mail->fromDomain());
    }

    public function testTheMessageIsFingerprintedByItsBytes(): void
    {
        $raw = $this->raw('auth-results.eml');
        $mail = new ZbatesonMailReader()->read($raw);

        self::assertSame(hash('sha256', $raw), $mail->sha256());
        self::assertSame(\strlen($raw), $mail->size());
    }

    public function testAMailWithoutSenderOrDateGivesNull(): void
    {
        $mail = new ZbatesonMailReader()->read("Subject: Hello\nDate: not a date\n\nBody\n");

        self::assertNull($mail->from());
        self::assertNull($mail->fromDomain());
        self::assertNull($mail->messageId());
        self::assertNull($mail->date());
        self::assertSame('Hello', $mail->subject());
        self::assertSame("Body\n", $mail->text());
    }

    #[DataProvider('garbage')]
    public function testGarbageIsUnreadable(string $raw): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ZbatesonMailReader()->read($raw);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function garbage(): iterable
    {
        yield 'empty' => [''];
        yield 'only blank lines' => ["\r\n\r\n"];
        yield 'binary' => ["\x00\x01\x02\xFF\xFE binary"];
        yield 'plain prose' => ["This is not a mail at all.\nJust two lines.\n"];
        yield 'a body without headers' => ["\nAmount: 100,00 CZK\n"];
        // RFC 5322 §2.2.3: only a header can be folded, so a message cannot start with a continuation line.
        yield 'a folded first line' => [" ; dkim=pass header.d=kb.cz\r\nFrom: info@kb.cz\r\n\r\nAmount: 100,00 CZK\r\n"];
        yield 'a tab-folded first line' => ["\t; dmarc=pass header.from=kb.cz\nFrom: info@kb.cz\n\nAmount: 100,00 CZK\n"];
    }

    private function read(string $fixture): InboundMail
    {
        return new ZbatesonMailReader()->read($this->raw($fixture));
    }

    private function raw(string $fixture): string
    {
        $raw = file_get_contents(__DIR__.'/../../../Fixtures/Banking/mime/'.$fixture);
        self::assertIsString($raw);

        return $raw;
    }
}
