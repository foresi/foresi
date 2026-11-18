<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Infrastructure\Column;

use Foresi\Banking\Application\MovementRow;
use Foresi\Banking\Infrastructure\Controller\BankText;
use Foresi\Ui\Component\DataTable;
use Foresi\Ui\Contract\Column;
use Foresi\Ui\Contract\ListColumns;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The columns of the bank movements; each cell renders one MovementRow. The firm
 * shows when several firms have an account, the account when a firm has more than
 * one, as the firm names it otherwise. A paired movement links the invoices its
 * payment pays; one waiting says why. The bank mail has columns of its own.
 */
final readonly class MovementColumns implements ListColumns
{
    public const string LIST = 'banking.movements';
    /** The context keys: whether several firms, and more accounts than firms, have an account. */
    public const string SEVERAL_FIRMS = 'severalFirms';
    public const string ACCOUNT_COLUMN = 'accountColumn';

    public function __construct(private BankText $text, private TranslatorInterface $translator, private UrlGeneratorInterface $urls)
    {
    }

    public function columns(string $list): iterable
    {
        if (self::LIST !== $list) {
            return [];
        }
        $label = static fn (string $key): string => 'bank.movement.column.'.$key;

        return [
            new Column('date', $label('date'), $this->date(...), defaultPosition: 10, locked: true),
            new Column('firm', $label('firm'), static fn (MovementRow $row): string => $row->firmName, defaultPosition: 20, phone: false, truncate: true, available: static fn (array $context): bool => true === ($context[self::SEVERAL_FIRMS] ?? false)),
            new Column('account', $label('account'), fn (MovementRow $row): string => $this->text->account($row->accountLabel, $row->accountIban), defaultPosition: 30, phone: false, wide: true, truncate: true, available: static fn (array $context): bool => true === ($context[self::ACCOUNT_COLUMN] ?? false)),
            new Column('amount', $label('amount'), $this->amount(...), defaultPosition: 40, align: 'right'),
            new Column('vs', $label('vs'), static fn (MovementRow $row): string => $row->variableSymbol ?? '', defaultPosition: 50, phone: false),
            new Column('partner', $label('partner'), self::partner(...), defaultPosition: 60, phone: false, truncate: true),
            new Column('message', $label('message'), static fn (MovementRow $row): string => $row->message ?? '', defaultPosition: 70, phone: false, wide: true, truncate: true),
            new Column('matched', $label('matched'), $this->matched(...), defaultPosition: 80, phone: false),
        ];
    }

    public function date(MovementRow $row): string
    {
        return $row->bookedOn->format($this->translator->trans('format.date'));
    }

    public function amount(MovementRow $row): string
    {
        return $this->text->amount($row->amount, $row->isIncoming());
    }

    public static function partner(MovementRow $row): string
    {
        return implode(' · ', array_filter([$row->counterName, $row->counterAccount], static fn (?string $part): bool => null !== $part && '' !== $part));
    }

    /**
     * The invoices the payment pays, each opening over the list in the side sheet
     * (docs/design/overlays.md), or where the movement stands.
     *
     * @return array<string, mixed>
     */
    private function matched(MovementRow $row): array
    {
        if ([] === $row->invoices) {
            return $this->text->state($row->state, $row->reason);
        }

        return ['links' => array_map(fn (string $invoiceId, string $number): array => ['text' => $number, 'href' => $this->urls->generate('app_invoice_show', ['id' => $invoiceId]), 'opens' => DataTable::OPENS_SHEET], array_keys($row->invoices), array_map(strval(...), array_values($row->invoices)))];
    }
}
