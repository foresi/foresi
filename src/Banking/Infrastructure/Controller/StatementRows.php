<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Infrastructure\Controller;

use Foresi\Banking\Application\StatementChain;
use Foresi\Banking\Application\StatementGap;
use Foresi\Banking\Contract\StatementGapKind;
use Foresi\Banking\Domain\ReceivedStatement;
use Foresi\Shared\Domain\Money;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The tables of the Statements tab: one per account and source, newest statement
 * first, with the number, period, balances, entries and when it came. A gap is
 * said under the statement after it, as Odoo says on a statement that its opening
 * balance does not follow on "…or an earlier statement is missing"; a statement
 * whose entries do not add up says so under its closing balance.
 */
final readonly class StatementRows
{
    private const array COLUMNS = [
        ['number', 'left', true],
        ['period', 'left', true],
        ['opening', 'right', false],
        ['closing', 'right', true],
        ['entries', 'right', false],
        ['received', 'left', false],
    ];

    public function __construct(private BankText $text, private TranslatorInterface $translator)
    {
    }

    /**
     * @param list<ReceivedStatement> $statements
     *
     * @return list<array{title: string, source: string, columns: list<array<string, mixed>>, rows: list<array<string, mixed>>}>
     */
    public function chains(array $statements): array
    {
        $alerts = [];
        foreach (StatementChain::gaps($statements) as $gap) {
            $alerts[$gap->statement->id()][] = $gap;
        }
        $columns = array_map(fn (array $column): array => [
            'key' => $column[0],
            'label' => $this->translator->trans('bank.statement.column.'.$column[0]),
            'align' => $column[1],
            'phone' => $column[2],
        ], self::COLUMNS);

        $tables = [];
        foreach (StatementChain::chains($statements) as $chain) {
            $first = $chain[0];
            $account = $first->account();
            $tables[] = [
                'title' => $this->text->account($account->label(), $account->iban()),
                'source' => $first->sourceId(),
                'columns' => $columns,
                'rows' => array_map(fn (ReceivedStatement $statement): array => $this->row($statement, $alerts[$statement->id()] ?? []), array_reverse($chain)),
            ];
        }

        return $tables;
    }

    /**
     * @param list<StatementGap> $gaps
     *
     * @return array<string, mixed>
     */
    private function row(ReceivedStatement $statement, array $gaps): array
    {
        $none = $this->translator->trans('bank.statement.none');
        $number = $statement->sequence()?->label() ?? $statement->statementRef() ?? $none;
        $alert = null;
        if ([] !== $gaps) {
            $alert = [
                'text' => implode(' ', array_map($this->gapText(...), $gaps)),
                'column' => StatementGapKind::Inner === $gaps[0]->kind ? 'closing' : 'opening',
                'href' => null,
                'action' => null,
            ];
        }

        return [
            'id' => $statement->id(),
            'label' => $number,
            'cells' => [
                'number' => $number,
                'period' => $this->period($statement) ?? $none,
                'opening' => $this->money($statement->openingBalance()) ?? $none,
                'closing' => $this->money($statement->closingBalance()) ?? $none,
                'entries' => (string) $statement->entryCount(),
                'received' => $this->text->time($statement->receivedAt()),
            ],
            'alert' => $alert,
        ];
    }

    private function gapText(StatementGap $gap): string
    {
        return match ($gap->kind) {
            StatementGapKind::Number => $this->translator->trans('bank.statement.gap.number', [
                'previous' => $gap->previous?->sequence()?->label() ?? '',
                'current' => $gap->statement->sequence()?->label() ?? '',
            ]),
            StatementGapKind::Balance => $this->translator->trans('bank.statement.gap.balance', [
                'actual' => $this->money($gap->actual) ?? '',
                'expected' => $this->money($gap->expected) ?? '',
                'difference' => !$gap->actual instanceof Money || !$gap->expected instanceof Money ? '' : $this->text->amount($this->abs($gap->actual->minus($gap->expected))),
            ]),
            StatementGapKind::Inner => $this->translator->trans('bank.statement.gap.inner', [
                'expected' => $this->money($gap->expected) ?? '',
                'actual' => $this->money($gap->actual) ?? '',
            ]),
        };
    }

    private function period(ReceivedStatement $statement): ?string
    {
        $format = $this->translator->trans('format.date');
        $from = $statement->periodFrom()?->format($format);
        $to = $statement->periodTo()?->format($format);
        if (null === $from || null === $to) {
            return $from ?? $to;
        }

        return $from === $to ? $from : $from."\u{a0}\u{2013} ".$to;
    }

    private function money(?Money $money): ?string
    {
        return $money instanceof Money ? $this->text->amount($money) : null;
    }

    private function abs(Money $money): Money
    {
        return Money::ofMinor(abs($money->minor()), $money->currency());
    }
}
