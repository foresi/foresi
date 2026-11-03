<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Domain;

use App\Invoicing\Domain\DocumentParty;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\InvoiceSend;
use App\Invoicing\Domain\SendStatus;
use App\Party\Domain\Address;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\PaymentMethod;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InvoiceSendTest extends TestCase
{
    public function testFreezesWhatIsSentAndTheFiguresOfTheDocument(): void
    {
        $send = $this->queued(['to' => ['billing@example.com'], 'cc' => ['office@example.com']]);

        self::assertSame(SendStatus::Queued, $send->status());
        self::assertSame(['billing@example.com'], $send->to());
        self::assertSame(['office@example.com'], $send->cc());
        self::assertSame('Invoice 20260001', $send->subject());
        self::assertSame('20260001', $send->documentNumber());
        self::assertSame('Example Customer a.s.', $send->customerName());
        self::assertSame(121000, $send->amountMinor());
        self::assertFalse($send->isSettingsProblem());
    }

    public function testRecipientsUpToTheLimitAreTakenAndToAndCcCountTogether(): void
    {
        $to = $this->addresses('to', 10);
        $cc = $this->addresses('cc', InvoiceSend::MAX_RECIPIENTS - 10);

        self::assertCount(InvoiceSend::MAX_RECIPIENTS - 10, $this->queued(['to' => $to, 'cc' => $cc])->cc());

        $this->expectException(\InvalidArgumentException::class);
        $this->queued(['to' => $to, 'cc' => [...$cc, 'one-more@example.com']]);
    }

    public function testTheSubjectAndBodyLimitsCountCharactersNotBytes(): void
    {
        $send = $this->queued(['subject' => str_repeat('č', InvoiceSend::MAX_SUBJECT), 'body' => str_repeat('č', InvoiceSend::MAX_BODY)]);

        self::assertSame(InvoiceSend::MAX_SUBJECT, mb_strlen($send->subject()));
        self::assertSame(InvoiceSend::MAX_BODY, mb_strlen($send->body()));
    }

    /**
     * @param array<string, mixed> $values
     */
    #[DataProvider('refused')]
    public function testAValueThatCouldBreakTheMailIsRefused(array $values): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->queued($values);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function refused(): iterable
    {
        yield 'nobody to send to' => [['to' => []]];
        yield 'a line break in the subject' => [['subject' => "Invoice\r\nBcc: x@example.com"]];
        yield 'a line separator in the subject' => [['subject' => "Invoice\u{2028}Bcc: x@example.com"]];
        yield 'a line break in a later to' => [['to' => ['billing@example.com', "other@example.com\nBcc: x@example.com"]]];
        yield 'a line break in a cc' => [['cc' => ["office@example.com\nBcc: x@example.com"]]];
        yield 'a line break in the copy address' => [['copyTo' => "me@example.com\nBcc: x@example.com", 'copyTokenHash' => str_repeat('b', 64)]];
        yield 'a blank subject' => [['subject' => '   ']];
        yield 'a subject past its limit' => [['subject' => str_repeat('č', InvoiceSend::MAX_SUBJECT + 1)]];
        yield 'a body past its limit' => [['body' => str_repeat('č', InvoiceSend::MAX_BODY + 1)]];
        yield 'a copy without its link' => [['copyTo' => 'me@example.com']];
        yield 'a link without its copy' => [['copyTokenHash' => str_repeat('b', 64)]];
    }

    public function testATryThatFailedKeepsItsCodeAndCutsItsTextsByCharacters(): void
    {
        $send = $this->queued();

        $send->recordAttempt(new \DateTimeImmutable('2026-11-03 10:00'), str_repeat('č', 600), true, str_repeat('ř', 40), 451);
        self::assertSame(1, $send->attempts());
        self::assertSame(str_repeat('č', InvoiceSend::MAX_ERROR), $send->lastError());
        self::assertSame(str_repeat('ř', InvoiceSend::MAX_CODE), $send->lastErrorCode());
        self::assertSame(451, $send->lastErrorSmtp());
        self::assertTrue($send->isSettingsProblem());

        $send->recordAttempt(new \DateTimeImmutable('2026-11-03 10:05'), 'The connection dropped.');
        self::assertSame(2, $send->attempts());
        self::assertNull($send->lastErrorCode());
        self::assertFalse($send->isSettingsProblem(), 'A try is no settings problem unless said so.');

        $send->recordAttempt(new \DateTimeImmutable('2026-11-03 10:10'), 'The server refused.', false, 'smtp_refused');
        self::assertSame('smtp_refused', $send->lastErrorCode());
    }

    public function testASendThatWentOutForgetsItsLastFailure(): void
    {
        $send = $this->queued();
        $send->recordAttempt(new \DateTimeImmutable('2026-11-03 10:00'), 'The login was refused.', true, 'smtp_auth', 535);

        $send->markSent(new \DateTimeImmutable('2026-11-03 10:05'), '<1@example.com>');

        self::assertSame(SendStatus::Sent, $send->status());
        self::assertNull($send->lastError());
        self::assertNull($send->lastErrorCode());
        self::assertNull($send->lastErrorSmtp());
        self::assertFalse($send->isSettingsProblem());
    }

    public function testAFailureKeepsItsCodeCutByCharacters(): void
    {
        $send = $this->queued();

        $send->fail(new \DateTimeImmutable('2026-11-03 10:00'), str_repeat('č', 600), false, str_repeat('ř', 40), 550);

        self::assertSame(SendStatus::Failed, $send->status());
        self::assertSame(str_repeat('č', InvoiceSend::MAX_ERROR), $send->lastError());
        self::assertSame(str_repeat('ř', InvoiceSend::MAX_CODE), $send->lastErrorCode());
        self::assertSame(550, $send->lastErrorSmtp());
    }

    public function testAFailureIsNoSettingsProblemUnlessSaidSo(): void
    {
        $send = $this->queued();

        $send->fail(new \DateTimeImmutable('2026-11-03 10:00'), 'Out of tries.');

        self::assertFalse($send->isSettingsProblem());
        self::assertNull($send->lastErrorCode());
    }

    public function testAFailedCopyKeepsItsCodeCutByCharacters(): void
    {
        $send = $this->queued(['copyTo' => 'me@example.com', 'copyTokenHash' => str_repeat('b', 64)]);

        $send->recordCopyFailure(str_repeat('č', 600), str_repeat('ř', 40), 550);

        self::assertSame(str_repeat('č', InvoiceSend::MAX_ERROR), $send->copyError());
        self::assertSame(str_repeat('ř', InvoiceSend::MAX_CODE), $send->copyErrorCode());
        self::assertSame(550, $send->copyErrorSmtp());
        self::assertFalse($send->isCopyOwed());
    }

    public function testRevokingAgainKeepsTheFirstMoment(): void
    {
        $send = $this->queued();

        $send->revoke(new \DateTimeImmutable('2026-11-03 10:00'));
        $send->revoke(new \DateTimeImmutable('2026-11-03 11:00'));

        self::assertEquals(new \DateTimeImmutable('2026-11-03 10:00'), $send->revokedAt());
    }

    public function testTheTenantIsKnownOnlyOnceStored(): void
    {
        $this->expectException(\LogicException::class);

        $this->queued()->tenantId();
    }

    /**
     * @return list<string>
     */
    private function addresses(string $prefix, int $count): array
    {
        return array_map(static fn (int $n): string => $prefix.$n.'@example.com', range(1, $count));
    }

    /**
     * @param array<string, mixed> $values named arguments of InvoiceSend::queue
     */
    private function queued(array $values = []): InvoiceSend
    {
        $invoice = self::createStub(Invoice::class);
        $address = new Address('Example Street 1', 'Example City', '100 00', 'CZ');
        $invoice->method('language')->willReturn(DocumentLanguage::Czech);
        $invoice->method('version')->willReturn(1);
        $invoice->method('number')->willReturn('20260001');
        $invoice->method('issuer')->willReturn(new DocumentParty('Example Firm s.r.o.', '12345695', null, $address, '', null, '', '', null, null));
        $invoice->method('customer')->willReturn(new DocumentParty('Example Customer a.s.', '87654320', null, $address, '', 'billing@example.com', '', '', null, null));
        $invoice->method('payable')->willReturn(Money::ofMinor(121000, Currency::CZK));
        $invoice->method('currency')->willReturn(Currency::CZK);
        $invoice->method('dueDate')->willReturn(new \DateTimeImmutable('2026-11-15'));
        $invoice->method('paymentMethod')->willReturn(PaymentMethod::BankTransfer);
        $invoice->method('bankAccount')->willReturn(null);
        $invoice->method('variableSymbol')->willReturn('20260001');

        $send = new \ReflectionMethod(InvoiceSend::class, 'queue')->invokeArgs(null, array_merge([
            'id' => '0190a0b4-0000-7000-8000-000000000001',
            'invoice' => $invoice,
            'to' => ['billing@example.com'],
            'cc' => [],
            'copyTo' => null,
            'subject' => 'Invoice 20260001',
            'body' => "Dear customer,\n{link}",
            'createdBy' => null,
            'createdAt' => new \DateTimeImmutable('2026-11-03 09:00'),
            'tokenHash' => str_repeat('a', 64),
            'copyTokenHash' => null,
            'pdfKey' => 'sends/0190a0b4.pdf',
            'pdfSha256' => str_repeat('c', 64),
        ], $values));
        self::assertInstanceOf(InvoiceSend::class, $send);

        return $send;
    }
}
