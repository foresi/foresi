<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Ui\Slot;

use Foresi\Ui\Slot\SlotItem;
use Foresi\Ui\Slot\SlotProvider;
use Foresi\Ui\Slot\Slots;
use PHPUnit\Framework\TestCase;

final class SlotsTest extends TestCase
{
    public function testCollectsTheItemsOfOneSlotLightestFirst(): void
    {
        $slots = new Slots([
            $this->provider('tabs', new SlotItem(data: ['key' => 'b'], weight: 20), new SlotItem(data: ['key' => 'a'], weight: 10)),
            $this->provider('menu', new SlotItem('menu.html.twig')),
            $this->provider('tabs', new SlotItem(data: ['key' => 'c'], weight: 15)),
        ]);

        self::assertSame(['a', 'c', 'b'], array_map(static fn (SlotItem $item): mixed => $item->data['key'], $slots->items('tabs')));
        self::assertSame('menu.html.twig', $slots->items('menu')[0]->template);
    }

    public function testASlotNobodyFillsIsEmpty(): void
    {
        self::assertSame([], new Slots([])->items('tabs'));
        self::assertSame([], new Slots([$this->provider('menu', new SlotItem('menu.html.twig'))])->items('tabs'));
    }

    public function testPassesTheContextToTheProviders(): void
    {
        $provider = new class implements SlotProvider {
            public function items(string $slot, array $context): iterable
            {
                return [new SlotItem(data: $context)];
            }
        };

        self::assertSame(['firmId' => 'f1'], new Slots([$provider])->items('tabs', ['firmId' => 'f1'])[0]->data);
    }

    public function testRefusesAnEmptyTemplate(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SlotItem('');
    }

    private function provider(string $slot, SlotItem ...$items): SlotProvider
    {
        return new readonly class($slot, array_values($items)) implements SlotProvider {
            /**
             * @param list<SlotItem> $items
             */
            public function __construct(private string $slot, private array $items)
            {
            }

            public function items(string $slot, array $context): iterable
            {
                return $slot === $this->slot ? $this->items : [];
            }
        };
    }
}
