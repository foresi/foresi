<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Invoicing\Application\InvoiceStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(InvoiceStatus::class)]
final class InvoiceStatusTest extends TestCase
{
    /**
     * @return iterable<string, array{bool, bool, bool, InvoiceStatus, string}>
     */
    public static function states(): iterable
    {
        yield 'issued' => [false, false, false, InvoiceStatus::Issued, 'gray'];
        yield 'delivered' => [true, false, false, InvoiceStatus::Delivered, 'green'];
        yield 'overdue' => [false, true, false, InvoiceStatus::Overdue, 'red'];
        yield 'overdue outranks delivered' => [true, true, false, InvoiceStatus::Overdue, 'red'];
        yield 'the trash outranks everything' => [true, true, true, InvoiceStatus::Trashed, 'amber'];
    }

    #[DataProvider('states')]
    public function testShowsTheMostRelevantState(bool $delivered, bool $overdue, bool $trashed, InvoiceStatus $status, string $tone): void
    {
        self::assertSame($status, InvoiceStatus::of($delivered, $overdue, $trashed));
        self::assertSame($tone, $status->tone());
    }
}
