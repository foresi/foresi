<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Mail\Domain;

use Foresi\Mail\Domain\InboundAddress;
use Foresi\Mail\Domain\InboundChannel;
use Foresi\Mail\Domain\MailMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a mail message accepts when it is logged, and how it trims what the mail said.
 */
final class MailMessageTest extends TestCase
{
    private const string HASH = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function testKeepsWhatCame(): void
    {
        $message = $this->message(rawKey: str_repeat('k', MailMessage::MAX_RAW_KEY), size: 0, from: '  bank@example.com  ', subject: 'Payment received');

        self::assertSame(self::HASH, $message->dedupKey());
        self::assertSame(str_repeat('k', MailMessage::MAX_RAW_KEY), $message->rawKey());
        self::assertSame(0, $message->size());
        self::assertSame('bank@example.com', $message->fromAddress());
        self::assertSame('Payment received', $message->subject());
    }

    public function testABlankSenderIsNoSender(): void
    {
        self::assertNull($this->message(from: '   ')->fromAddress());
        self::assertNull($this->message(from: null)->fromAddress());
    }

    public function testASenderAndASubjectAreCutByCharacters(): void
    {
        $message = $this->message(from: str_repeat('ř', MailMessage::MAX_FROM + 5), subject: str_repeat('é', MailMessage::MAX_SUBJECT + 5));

        self::assertSame(str_repeat('ř', MailMessage::MAX_FROM), $message->fromAddress());
        self::assertSame(str_repeat('é', MailMessage::MAX_SUBJECT), $message->subject());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badHashes(): iterable
    {
        yield 'something after the hash' => [self::HASH.'0'];
        yield 'something before the hash' => ['0'.self::HASH];
        yield 'a line break after the hash' => [self::HASH."\n"];
    }

    #[DataProvider('badHashes')]
    public function testADedupKeyIsExactlyASha256(string $hash): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A dedup key and a raw hash are sha256 in lowercase hex.');
        $this->message(dedupKey: $hash);
    }

    #[DataProvider('badHashes')]
    public function testARawHashIsExactlyASha256(string $hash): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A dedup key and a raw hash are sha256 in lowercase hex.');
        $this->message(rawSha256: $hash);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badRawKeys(): iterable
    {
        yield 'missing' => [''];
        yield 'too long' => [str_repeat('k', MailMessage::MAX_RAW_KEY + 1)];
    }

    #[DataProvider('badRawKeys')]
    public function testARawKeyIsPresentAndFitsItsColumn(string $rawKey): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('The key of the raw file is missing or too long.');
        $this->message(rawKey: $rawKey);
    }

    public function testANegativeSizeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A size is never negative.');
        $this->message(size: -1);
    }

    public function testHeadersAreTrimmedBlankOnesAreNoneAndLongOnesAreCutByCharacters(): void
    {
        $message = $this->message(messageId: '  <id@mail.example.com>  ', inReplyTo: '   ');

        self::assertSame('<id@mail.example.com>', $message->messageIdHeader());
        self::assertNull($message->inReplyTo());

        $long = $this->message(messageId: str_repeat('ř', MailMessage::MAX_HEADER + 5));

        self::assertSame(str_repeat('ř', MailMessage::MAX_HEADER), $long->messageIdHeader());
    }

    public function testReferencesAreTrimmedAndBlankOnesDropped(): void
    {
        $message = $this->message(references: ['  <a@mail.example.com> ', '', '   ', '<b@mail.example.com>']);

        self::assertSame(['<a@mail.example.com>', '<b@mail.example.com>'], $message->references());
        self::assertSame([], $this->message(references: ['', '  '])->references());
    }

    /**
     * @param list<string> $references
     */
    private function message(
        string $dedupKey = self::HASH,
        string $rawKey = 'raw/message.eml',
        string $rawSha256 = self::HASH,
        int $size = 10,
        ?string $from = 'bank@example.com',
        string $subject = 'Subject',
        ?string $messageId = null,
        ?string $inReplyTo = null,
        array $references = [],
    ): MailMessage {
        $address = new InboundAddress('test', null, 'Test', self::HASH, null, new \DateTimeImmutable('2026-11-20 09:00:00'));

        return new MailMessage('00000000-0000-4000-8000-000000000001', $address, InboundChannel::Imap, $dedupKey, $rawKey, $rawSha256, $size, $messageId, $inReplyTo, $references, $from, $subject, null, new \DateTimeImmutable('2026-11-20 10:00:00'), 'unknown', null);
    }
}
