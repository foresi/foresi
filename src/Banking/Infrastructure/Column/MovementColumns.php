<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Infrastructure\Column;

use Foresi\Banking\Application\MovementRow;
use Foresi\Banking\Infrastructure\Controller\BankText;
use Foresi\Shared\Domain\Money;
use Foresi\Shared\Domain\View\AttributeType;
use Foresi\Ui\Contract\Attribute;
use Foresi\Ui\Contract\DataSource;
use Foresi\Ui\Contract\Opens;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The columns of the bank movements; each cell renders one MovementRow. The firm
 * shows when several firms have an account, the account when a firm has more than
 * one, as the firm names it otherwise. A paired movement links the invoices its
 * payment pays; one waiting says why. The bank mail has columns of its own.
 */
final readonly class MovementColumns implements DataSource
{
    public const string LIST = 'banking.movements';
    /** The context keys: whether several firms, and more accounts than firms, have an account. */
    public const string SEVERAL_FIRMS = 'severalFirms';
    public const string ACCOUNT_COLUMN = 'accountColumn';

    public function __construct(private BankText $text, private TranslatorInterface $translator, private UrlGeneratorInterface $urls)
    {
    }

    public function attributes(string $list): iterable
    {
        if (self::LIST !== $list) {
            return [];
        }
        $label = static fn (string $key): string => 'bank.movement.column.'.$key;

        return [
            new Attribute('date', $label('date'), AttributeType::Date, static fn (MovementRow $row): \DateTimeImmutable => $row->bookedOn, defaultVisible: true, defaultPosition: 10, cell: $this->date(...), locked: true),
            new Attribute('firm', $label('firm'), AttributeType::Text, static fn (MovementRow $row): string => $row->firmName, defaultVisible: true, defaultPosition: 20, cell: static fn (MovementRow $row): string => $row->firmName, phone: false, truncate: true, available: static fn (array $context): bool => true === ($context[self::SEVERAL_FIRMS] ?? false)),
            new Attribute('account', $label('account'), AttributeType::Text, $this->account(...), defaultVisible: true, defaultPosition: 30, cell: $this->account(...), phone: false, wide: true, truncate: true, available: static fn (array $context): bool => true === ($context[self::ACCOUNT_COLUMN] ?? false)),
            new Attribute('amount', $label('amount'), AttributeType::Money, static fn (MovementRow $row): Money => $row->amount, defaultVisible: true, defaultPosition: 40, cell: $this->amount(...)),
            new Attribute('vs', $label('vs'), AttributeType::Text, static fn (MovementRow $row): ?string => $row->variableSymbol, defaultVisible: true, defaultPosition: 50, cell: static fn (MovementRow $row): string => $row->variableSymbol ?? '', phone: false),
            new Attribute('partner', $label('partner'), AttributeType::Text, self::partner(...), defaultVisible: true, defaultPosition: 60, cell: self::partner(...), phone: false, truncate: true),
            new Attribute('message', $label('message'), AttributeType::Text, static fn (MovementRow $row): ?string => $row->message, defaultVisible: true, defaultPosition: 70, cell: static fn (MovementRow $row): string => $row->message ?? '', phone: false, wide: true, truncate: true),
            new Attribute('matched', $label('matched'), AttributeType::Text, static fn (MovementRow $row): string => $row->state->value, defaultVisible: true, defaultPosition: 80, cell: $this->matched(...), phone: false),
        ];
    }

    public function date(MovementRow $row): string
    {
        return $row->bookedOn->format($this->translator->trans('format.date'));
    }

    public function account(MovementRow $row): string
    {
        return $this->text->account($row->accountLabel, $row->accountIban);
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

        return ['links' => array_map(fn (string $invoiceId, string $number): array => ['text' => $number, 'href' => $this->urls->generate('app_invoice_show', ['id' => $invoiceId]), 'opens' => Opens::SHEET], array_keys($row->invoices), array_map(strval(...), array_values($row->invoices)))];
    }
}
