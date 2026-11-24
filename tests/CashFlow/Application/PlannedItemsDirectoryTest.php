<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\CashFlow\Application;

use Foresi\CashFlow\Contract\PlannedItemDirectory;
use Foresi\Tests\CashFlow\Support\CashFlowTestCase;

/**
 * The directory gives the summary of a planned item with the url of its sheet, and the occurrence a day belongs to.
 */
final class PlannedItemsDirectoryTest extends CashFlowTestCase
{
    public function testGivesTheSummaryWithTheUrlOfTheItemSheet(): void
    {
        $item = $this->planned(on: '2026-05-20', repeat: 'month', counterpartyId: $this->customer->id());
        $directory = self::getContainer()->get(PlannedItemDirectory::class);

        $summary = $directory->one($item->id());

        self::assertNotNull($summary);
        self::assertSame('/cash-flow/planned-costs/'.$item->id(), $summary->url);
        self::assertTrue($summary->recurring);
        self::assertSame($this->customer->id(), $summary->counterpartyId);
        self::assertNull($directory->one('01937f4e-0000-7000-8000-000000000001'));
        self::assertSame('2026-06-20', $directory->occurrenceHolding($item->id(), new \DateTimeImmutable('2026-06-02'))?->format('Y-m-d'));
    }

    public function testListsThePlannedCostsByNameAndNoIncome(): void
    {
        $this->planned(name: 'Voice API', category: 'software');
        $this->planned(name: 'Cloud Host', category: 'software', repeat: 'month');
        $this->planned(name: 'Retainer', direction: 'in', category: 'revenue');

        $costs = self::getContainer()->get(PlannedItemDirectory::class)->costs();

        self::assertSame(['Cloud Host', 'Voice API'], array_map(static fn ($item): string => $item->name, $costs));
        self::assertSame([true, false], array_map(static fn ($item): bool => $item->recurring, $costs));
    }
}
