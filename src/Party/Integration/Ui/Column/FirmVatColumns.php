<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Integration\Ui\Column;

use Foresi\Party\Application\View\FirmVatLists;
use Foresi\Shared\Domain\View\AttributeType;
use Foresi\Ui\Contract\Attribute;
use Foresi\Ui\Contract\DataSource;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The columns of the VAT statuses of the firm, those the tab always showed: the status, the day
 * it holds from and the last day it holds.
 */
final readonly class FirmVatColumns implements DataSource
{
    public const string LIST = FirmVatLists::KEY;

    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function attributes(string $list): iterable
    {
        if (self::LIST !== $list) {
            return [];
        }
        $label = static fn (string $key): string => 'firm.vat.column.'.$key;
        $fromText = fn (VatStatusRow $row): string => $row->from ?? $this->translator->trans('firm.vat.from_start');
        $untilText = fn (VatStatusRow $row): string => $row->until ?? $this->translator->trans('firm.vat.until_next');

        return [
            new Attribute('status', $label('status'), AttributeType::Text, static fn (VatStatusRow $row): string => $row->label, defaultVisible: true, defaultPosition: 10, cell: static fn (VatStatusRow $row): string => $row->label, locked: true, truncate: true),
            new Attribute('from', $label('from'), AttributeType::Text, $fromText, defaultVisible: true, defaultPosition: 20, cell: fn (VatStatusRow $row): array|string => $row->from ?? ['empty' => $fromText($row)]),
            new Attribute('until', $label('until'), AttributeType::Text, $untilText, defaultVisible: true, defaultPosition: 30, cell: fn (VatStatusRow $row): array|string => $row->until ?? ['empty' => $untilText($row)], phone: false),
        ];
    }
}
