<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Infrastructure\Column;

use Foresi\Banking\Application\View\FirmBankAccountLists;
use Foresi\Banking\Domain\BankFeed;
use Foresi\Shared\Domain\View\AttributeType;
use Foresi\Ui\Contract\Attribute;
use Foresi\Ui\Contract\DataSource;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The columns of the bank accounts of the firm, those the tab always showed: the account, its
 * IBAN and currency, its state and whether it has a bank address.
 */
final readonly class FirmBankAccountColumns implements DataSource
{
    public const string LIST = FirmBankAccountLists::KEY;

    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function attributes(string $list): iterable
    {
        if (self::LIST !== $list) {
            return [];
        }
        $label = static fn (string $key): string => 'bank.account.column.'.$key;
        $state = fn (FirmBankAccountRow $row): array => match (true) {
            $row->account->isArchived() => ['text' => $this->translator->trans('bank.account.state.archived'), 'tone' => 'gray'],
            $row->account->isPrimary() => ['text' => $this->translator->trans('bank.account.state.primary'), 'tone' => 'green'],
            default => ['text' => $this->translator->trans('bank.account.state.active'), 'tone' => 'gray'],
        };
        $address = fn (FirmBankAccountRow $row): array => $row->feed instanceof BankFeed
            ? ['text' => $this->translator->trans('bank.account.address.state.set'), 'tone' => 'green']
            : ['text' => $this->translator->trans('bank.account.address.state.none'), 'tone' => 'gray'];

        return [
            new Attribute('name', $label('name'), AttributeType::Text, static fn (FirmBankAccountRow $row): string => $row->name, defaultVisible: true, defaultPosition: 10, cell: static fn (FirmBankAccountRow $row): string => $row->name, locked: true),
            new Attribute('iban', $label('iban'), AttributeType::Text, static fn (FirmBankAccountRow $row): string => $row->iban, defaultVisible: true, defaultPosition: 20, cell: static fn (FirmBankAccountRow $row): string => $row->iban, phone: false),
            new Attribute('currency', $label('currency'), AttributeType::Text, static fn (FirmBankAccountRow $row): string => $row->account->currency()->value, defaultVisible: true, defaultPosition: 30, cell: static fn (FirmBankAccountRow $row): string => $row->account->currency()->value, phone: false),
            new Attribute('state', $label('state'), AttributeType::Text, fn (FirmBankAccountRow $row): string => $state($row)['text'], defaultVisible: true, defaultPosition: 40, cell: $state),
            new Attribute('address', $label('address'), AttributeType::Text, fn (FirmBankAccountRow $row): string => $address($row)['text'], defaultVisible: true, defaultPosition: 50, cell: $address, phone: false),
        ];
    }
}
