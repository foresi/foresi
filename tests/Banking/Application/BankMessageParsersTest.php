<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Banking\Application;

use App\Banking\Application\BankMessageParser;
use App\Banking\Application\BankMessageParsers;
use App\Banking\Application\InboundMail;
use App\Banking\Application\ParseFailed;
use App\Banking\Application\ParseFailure;
use App\Banking\Application\ParseResult;
use PHPUnit\Framework\TestCase;

final class BankMessageParsersTest extends TestCase
{
    public function testTheOneSupportingParserIsChosen(): void
    {
        $parsers = new BankMessageParsers([$this->parser('first', false), $this->parser('second', true)]);

        self::assertSame('second', $parsers->for($this->mail())?->id());
    }

    public function testTwoSupportingParsersAreAmbiguous(): void
    {
        $parsers = new BankMessageParsers([$this->parser('first', true), $this->parser('other', false), $this->parser('second', true)]);

        try {
            $parsers->for($this->mail());
            self::fail('Two parsers claimed one mail.');
        } catch (ParseFailed $failed) {
            self::assertSame(ParseFailure::Ambiguous, $failed->reason);
            self::assertSame('Parsers first, second all support this mail.', $failed->detail);
            self::assertSame($failed->detail, $failed->getMessage());
        }
    }

    public function testNoSupportingParserGivesNull(): void
    {
        self::assertNull(new BankMessageParsers([$this->parser('first', false)])->for($this->mail()));
        self::assertNull(new BankMessageParsers([])->for($this->mail()));
    }

    public function testAParserIsFoundByItsId(): void
    {
        $parsers = new BankMessageParsers([$this->parser('first', false), $this->parser('second', false)]);

        self::assertSame('second', $parsers->byId('second')?->id());
        self::assertNull($parsers->byId('third'));
    }

    private function mail(): InboundMail
    {
        return new InboundMail(
            headers: [['From', 'notices@bank.example.com']],
            from: 'notices@bank.example.com',
            subject: 'Payment notice',
            messageId: 'notice-1@bank.example.com',
            date: null,
            text: 'Amount: 100,00 CZK',
            html: null,
            attachmentNames: [],
            sha256: hash('sha256', 'raw'),
            size: 3,
        );
    }

    private function parser(string $id, bool $supports): BankMessageParser
    {
        return new readonly class($id, $supports) implements BankMessageParser {
            public function __construct(private string $id, private bool $supports)
            {
            }

            public function id(): string
            {
                return $this->id;
            }

            public function version(): int
            {
                return 1;
            }

            public function senderDomains(): array
            {
                return ['bank.example.com'];
            }

            public function supports(InboundMail $mail): bool
            {
                return $this->supports;
            }

            public function parse(InboundMail $mail): ParseResult
            {
                return ParseResult::ignored('not a movement');
            }
        };
    }
}
