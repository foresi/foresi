<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Banking\Application;

use App\Banking\Application\BankMessageParsers;
use App\Banking\Application\InboundMessages;
use App\Banking\Application\ParseFailed;
use App\Banking\Application\ParseFailure;
use App\Banking\Application\ReparseFailedMessages;
use App\Banking\Domain\InboundStatus;
use App\Country\CzechRepublic\Banking\KbNotificationParser;
use App\Invoicing\Application\PaymentState;
use App\Tests\Banking\Support\FixedParser;
use App\Tests\Banking\Support\InboundMailKit;
use App\Tests\Banking\Support\InboundTestCase;
use Psr\Log\NullLogger;

/**
 * Failed and unrecognised messages are read again by the hourly job once the
 * parsers changed, and only then: a parser fix pays what its bug left unpaid.
 */
final class ReparseFailedMessagesTest extends InboundTestCase
{
    public function testItRunsHourlyInUtc(): void
    {
        $job = $this->job(new BankMessageParsers([new KbNotificationParser()]));

        self::assertSame('bank-reparse', $job->name());
        self::assertSame('2026-11-20T11', $job->period(new \DateTimeImmutable('2026-11-20 12:59:59', new \DateTimeZone('Europe/Prague'))));
        self::assertSame('2026-11-20T12', $job->period(new \DateTimeImmutable('2026-11-20 12:00:00', new \DateTimeZone('UTC'))));
    }

    public function testReparseRetriesOnlyOlderParserVersions(): void
    {
        $invoice = $this->kbInvoice();
        // Version 1, the parser of today, does not know the status of this notice.
        $older = $this->received($this->notice('incoming-unknown-status'));
        self::assertSame(InboundStatus::Failed, $this->process->process($older));
        // Version 2 failed on its own already: a run of version 2 has nothing new to say about it.
        $fixedFailing = new FixedParser('cz.kb', 2, ['kb.cz'], new ParseFailed(ParseFailure::MissingField, 'A payment has no amount.'));
        $newer = $this->received($this->notice('missing-amount'));
        self::assertSame(InboundStatus::Failed, $this->processWith(new BankMessageParsers([$fixedFailing]))->process($newer));

        // Version 2 reads the status the first one did not know.
        $release = new BankMessageParsers([new FixedParser('cz.kb', 2, ['kb.cz'], FixedParser::kbMovement())]);
        $this->job($release)->run(null);

        $row = $this->messageRow($older);
        self::assertSame(['processed', 'cz.kb', 2, 2, null], [$row['status'], $row['parser_id'], self::int($row['parser_version']), self::int($row['attempts']), $row['failure']]);
        self::assertSame(PaymentState::Paid, $this->stateOf($invoice));
        $untouched = $this->messageRow($newer);
        self::assertSame(['failed', 2, 1], [$untouched['status'], self::int($untouched['parser_version']), self::int($untouched['attempts'])]);

        // Nothing is due any more.
        $this->job($release)->run(null);
        self::assertSame(2, self::int($this->messageRow($older)['attempts']));
        self::assertSame(1, $this->paymentCount());
    }

    public function testANotBankMailBecomesBankMailWhenAParserAppears(): void
    {
        $kbOnly = new BankMessageParsers([new KbNotificationParser()]);
        $id = $this->received(InboundMailKit::mail($this->address));
        self::assertSame(InboundStatus::NotBankMail, $this->processWith($kbOnly)->process($id));

        // The same parsers again: the message is not read again.
        $this->job($kbOnly)->run(null);
        self::assertSame(1, self::int($this->messageRow($id)['attempts']));

        // A parser of the example bank arrives.
        $this->job(new BankMessageParsers([new KbNotificationParser(), new FixedParser('example.bank', 1, ['bank.example.com'], FixedParser::kbMovement())]))->run(null);

        $row = $this->messageRow($id);
        self::assertSame(['processed', 'example.bank', 1, 2, 'unverified'], [$row['status'], $row['parser_id'], self::int($row['parser_version']), self::int($row['attempts']), $row['trust']]);
        $movements = $this->movementsOf($id);
        self::assertCount(1, $movements);
        // Nothing vouches for the sender, so it waits for a person.
        self::assertSame(['unmatched', 'unverified'], [$movements[0]['state'], $movements[0]['unmatched_reason']]);
    }

    public function testAChangedRawFileAndARejectionAreNotRetried(): void
    {
        $rejected = $this->received($this->notice('incoming-booked', auth: 'aligned-dkim-fail'));
        self::assertSame(InboundStatus::Rejected, $this->process->process($rejected));

        $this->job(new BankMessageParsers([new FixedParser('cz.kb', 2, ['kb.cz'], FixedParser::kbMovement())]))->run(null);

        self::assertSame(['rejected', 1], [$this->messageRow($rejected)['status'], self::int($this->messageRow($rejected)['attempts'])]);
        self::assertSame([], $this->movementsOf($rejected));
    }

    private function job(BankMessageParsers $parsers): ReparseFailedMessages
    {
        return new ReparseFailedMessages(self::getContainer()->get(InboundMessages::class), $parsers, $this->processWith($parsers), new NullLogger());
    }
}
