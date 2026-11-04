<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Banking\Domain;

use App\Banking\Domain\InboundChannel;
use App\Banking\Domain\InboundMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What an inbound message accepts when it is logged, and how it trims what the mail said.
 */
final class InboundMessageTest extends TestCase
{
    private const string HASH = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function testKeepsWhatCame(): void
    {
        $message = $this->message(rawKey: str_repeat('k', InboundMessage::MAX_RAW_KEY), size: 0, from: '  bank@example.com  ', subject: 'Payment received');

        self::assertSame(self::HASH, $message->dedupKey());
        self::assertSame(str_repeat('k', InboundMessage::MAX_RAW_KEY), $message->rawKey());
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
        $message = $this->message(from: str_repeat('ř', InboundMessage::MAX_FROM + 5), subject: str_repeat('é', InboundMessage::MAX_SUBJECT + 5));

        self::assertSame(str_repeat('ř', InboundMessage::MAX_FROM), $message->fromAddress());
        self::assertSame(str_repeat('é', InboundMessage::MAX_SUBJECT), $message->subject());
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
        yield 'too long' => [str_repeat('k', InboundMessage::MAX_RAW_KEY + 1)];
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

    public function testTheTenantIsOnlyKnownOncePersisted(): void
    {
        $message = $this->message();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('The tenant is stamped on persist.');
        $message->tenantId();
    }

    private function message(
        string $dedupKey = self::HASH,
        string $rawKey = 'raw/message.eml',
        string $rawSha256 = self::HASH,
        int $size = 10,
        ?string $from = 'bank@example.com',
        string $subject = 'Subject',
    ): InboundMessage {
        return new InboundMessage('00000000-0000-4000-8000-000000000001', '00000000-0000-4000-8000-000000000002', InboundChannel::Imap, $dedupKey, $rawKey, $rawSha256, $size, $from, $subject, new \DateTimeImmutable('2026-11-20 10:00:00'));
    }
}
