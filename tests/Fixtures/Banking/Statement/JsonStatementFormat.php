<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Fixtures\Banking\Statement;

use Foresi\Banking\Contract\BankStatement;
use Foresi\Banking\Contract\MovementDirection;
use Foresi\Banking\Contract\ParsedMovement;
use Foresi\Banking\Contract\ParseFailed;
use Foresi\Banking\Contract\ParseFailure;
use Foresi\Banking\Contract\StatementFile;
use Foresi\Banking\Contract\StatementFormat;
use Foresi\Banking\Contract\StatementSequence;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;

/**
 * A tiny statement format for the tests, standing in for camt.053 or ABO/GPC: a JSON
 * object that starts with {"test-statement": 1, ...} and holds a list of statements.
 *
 *     {"test-statement": 1, "statements": [{"account": "CZ65...", "currency": "CZK",
 *      "id": "S-42", "number": 42, "year": 2026, "from": "2026-11-01", "to": "2026-11-30",
 *      "opening": "1000.00", "closing": "1210.00",
 *      "entries": [{"direction": "incoming", "amount": "210.00", "booked": "2026-11-20",
 *                   "vs": "20260001", "counter": "CZ55...", "ref": "L-1"}]}]}
 */
final readonly class JsonStatementFormat implements StatementFormat
{
    public const string ID = 'test.json';

    public function __construct(private string $id = self::ID)
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

    public function label(): string
    {
        return 'Test JSON';
    }

    public function supports(StatementFile $file): bool
    {
        return str_starts_with(ltrim($file->contents), '{"test-statement"');
    }

    public function read(StatementFile $file): array
    {
        try {
            $data = json_decode($file->contents, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $invalid) {
            throw new ParseFailed(ParseFailure::UnknownLayout, $invalid->getMessage());
        }
        if (!\is_array($data) || !\is_array($data['statements'] ?? null)) {
            throw new ParseFailed(ParseFailure::MissingField, 'statements');
        }

        return array_values(array_map($this->statement(...), $data['statements']));
    }

    private function statement(mixed $raw): BankStatement
    {
        if (!\is_array($raw)) {
            throw new ParseFailed(ParseFailure::InvalidValue, 'statement');
        }
        $currency = Currency::from($this->string($raw, 'currency') ?? 'CZK');
        $account = $this->string($raw, 'account') ?? throw new ParseFailed(ParseFailure::MissingField, 'account');
        $number = \is_int($raw['number'] ?? null) ? $raw['number'] : null;
        $year = \is_int($raw['year'] ?? null) ? $raw['year'] : null;
        $money = static fn (?string $amount): ?Money => null === $amount ? null : Money::of($amount, $currency);
        $date = static fn (?string $day): ?\DateTimeImmutable => null === $day ? null : new \DateTimeImmutable($day);
        $entries = [];
        foreach (\is_array($raw['entries'] ?? null) ? $raw['entries'] : [] as $entry) {
            if (!\is_array($entry)) {
                throw new ParseFailed(ParseFailure::InvalidValue, 'entry');
            }
            $entries[] = new ParsedMovement(
                'outgoing' === $this->string($entry, 'direction') ? MovementDirection::Outgoing : MovementDirection::Incoming,
                Money::of($this->string($entry, 'amount') ?? '0', $currency),
                $account,
                '123456789/0800',
                $this->string($entry, 'counter'),
                'Example Customer a.s.',
                new \DateTimeImmutable($this->string($entry, 'booked') ?? 'today'),
                $this->string($entry, 'vs'),
                null,
                null,
                'Payment for an invoice',
                $this->string($entry, 'ref'),
            );
        }

        return new BankStatement(
            $account,
            $currency,
            $entries,
            $this->string($raw, 'id'),
            null === $number ? null : (null === $year ? StatementSequence::electronic($number) : StatementSequence::withinYear($number, $year)),
            $date($this->string($raw, 'from')),
            $date($this->string($raw, 'to')),
            $money($this->string($raw, 'opening')),
            $money($this->string($raw, 'closing')),
        );
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    private function string(array $raw, string $key): ?string
    {
        return \is_string($raw[$key] ?? null) ? $raw[$key] : null;
    }
}
