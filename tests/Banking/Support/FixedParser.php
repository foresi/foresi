<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Banking\Support;

use App\Banking\Application\BankMessageParser;
use App\Banking\Application\InboundMail;
use App\Banking\Application\ParsedMovement;
use App\Banking\Application\ParseFailed;
use App\Banking\Application\ParseResult;
use App\Banking\Domain\MovementDirection;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;

/**
 * A parser of a made-up release: it knows any mail from its domains and reads
 * from every one the same answer, or the same failure.
 */
final readonly class FixedParser implements BankMessageParser
{
    /**
     * @param list<string> $domains
     */
    public function __construct(
        private string $id,
        private int $version,
        private array $domains,
        private ParseResult|ParseFailed $answer,
    ) {
    }

    /**
     * The movement of the KB notices: 150.00 CZK with VS 2026001 on the test IBAN.
     */
    public static function kbMovement(): ParseResult
    {
        return ParseResult::withMovements([new ParsedMovement(
            MovementDirection::Incoming,
            Money::of('150.00', Currency::CZK),
            'CZ6508000000192000145399',
            '123-4567890123/0100',
            'CZ1001000001234567890123',
            'Example Buyer Ltd',
            new \DateTimeImmutable('2026-11-02'),
            '2026001',
            '0308',
            null,
            null,
            null,
        )]);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function senderDomains(): array
    {
        return $this->domains;
    }

    public function supports(InboundMail $mail): bool
    {
        return \in_array($mail->fromDomain(), $this->domains, true);
    }

    public function parse(InboundMail $mail): ParseResult
    {
        if ($this->answer instanceof ParseFailed) {
            throw $this->answer;
        }

        return $this->answer;
    }
}
