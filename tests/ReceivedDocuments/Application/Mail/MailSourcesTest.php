<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\ReceivedDocuments\Application\Mail;

use Foresi\Mail\Contract\AddressIssuer;
use Foresi\ReceivedDocuments\Application\Mail\MailSources;
use Foresi\ReceivedDocuments\Domain\DocumentSource;
use Foresi\ReceivedDocuments\Domain\ReceivedDocument;
use Foresi\ReceivedDocuments\Domain\ReceivedDocumentContent;
use Foresi\Tests\ReceivedDocuments\Support\StoredMessages;
use PHPUnit\Framework\TestCase;

/**
 * Where a mail-filed document came from, with the capture address cut out of the sender's own text.
 */
final class MailSourcesTest extends TestCase
{
    private const string MAIL = '5f0b1c9e-1d52-4f0e-8d3c-2a7a9d0f4b11';

    public function testTheSenderAndTheSubjectAreMasked(): void
    {
        $issuer = self::createStub(AddressIssuer::class);
        $issuer->method('masked')->willReturnCallback(static fn (string $text): string => str_replace('secret-token', '[address]', $text));
        $sources = new MailSources(new StoredMessages(self::MAIL, '"docs+secret-token@example.com" <pat@supplier.example.com>', 'Re: docs+secret-token', new \DateTimeImmutable('2026-11-20 09:00:00'), 'failed'), $issuer);

        $source = $sources->of($this->document(self::MAIL));

        self::assertNotNull($source);
        self::assertSame('"docs+[address]@example.com" <pat@supplier.example.com>', $source->from);
        self::assertSame('Re: docs+[address]', $source->subject);
        self::assertSame('failed', $source->verdict);
    }

    public function testNothingForADocumentWithoutAMailOrWithAMailMailNoLongerHolds(): void
    {
        $sources = new MailSources(new StoredMessages(self::MAIL, null, 'Invoice', null), self::createStub(AddressIssuer::class));

        self::assertNull($sources->of($this->document(null)));
        self::assertNull($sources->of($this->document('7a0b1c9e-1d52-4f0e-8d3c-2a7a9d0f4b99')));
    }

    private function document(?string $mail): ReceivedDocument
    {
        $now = new \DateTimeImmutable('2026-11-20 09:05:00');

        return new ReceivedDocument(ReceivedDocumentContent::blank($now), 'user-1', $now, null === $mail ? DocumentSource::Upload : DocumentSource::Mail, $mail);
    }
}
