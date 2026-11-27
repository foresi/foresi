<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Party\Integration\Ui\Column;

use Foresi\Party\Integration\Ui\Column\FirmVatColumns;
use Foresi\Party\Integration\Ui\Column\VatStatusRow;
use Foresi\Tests\Identity\Integration\Ui\Column\ColumnTestSupport;
use PHPUnit\Framework\TestCase;

/**
 * The columns of the VAT statuses of the firm: the status, the day it holds from and the last
 * day it holds.
 */
final class FirmVatColumnsTest extends TestCase
{
    public function testAnotherListHasNoColumns(): void
    {
        self::assertSame([], new FirmVatColumns(ColumnTestSupport::translator())->attributes('party.firm'));
    }

    public function testItShowsTheStatusFromAndUntil(): void
    {
        self::assertSame([
            ['status', 'firm.vat.column.status', 'text', true, 10, true, true, true, []],
            ['from', 'firm.vat.column.from', 'text', true, 20, false, true, false, []],
            ['until', 'firm.vat.column.until', 'text', true, 30, false, false, false, []],
        ], ColumnTestSupport::describe(new FirmVatColumns(ColumnTestSupport::translator())->attributes('party.firm_vat')));
    }

    public function testADatedStatusShowsItsDaysAndAnOpenOneItsOwnWords(): void
    {
        $attributes = [...new FirmVatColumns(ColumnTestSupport::translator())->attributes('party.firm_vat')];
        $dated = new VatStatusRow('v1', 'VAT payer', '2026-01-01', '2026-06-30', false);
        $open = new VatStatusRow('v2', 'Not a VAT payer', null, null, true);

        $status = ColumnTestSupport::find($attributes, 'status');
        self::assertSame('VAT payer', $status->value($dated));
        self::assertSame('VAT payer', $status->cell($dated));
        $from = ColumnTestSupport::find($attributes, 'from');
        self::assertSame('2026-01-01', $from->value($dated));
        self::assertSame('2026-01-01', $from->cell($dated));
        self::assertSame('firm.vat.from_start', $from->value($open));
        self::assertSame(['empty' => 'firm.vat.from_start'], $from->cell($open));
        $until = ColumnTestSupport::find($attributes, 'until');
        self::assertSame('2026-06-30', $until->value($dated));
        self::assertSame('2026-06-30', $until->cell($dated));
        self::assertSame('firm.vat.until_next', $until->value($open));
        self::assertSame(['empty' => 'firm.vat.until_next'], $until->cell($open));
    }
}
