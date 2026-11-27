<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Application\View;

use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Work\Application\View\NotificationLists;
use Foresi\Work\Application\View\ProjectTaskLists;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The quick filters each list of the module pins by default, in their order: what every view
 * that keeps none of its own shows under its tabs. My tasks and the projects pin the client,
 * Work's Contacts integration's (tests/Work/Integration/Contacts).
 */
final class ListQuickFiltersTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function lists(): iterable
    {
        yield 'notifications' => ['notifications', ['reason', 'unread']];
        yield 'project tasks' => ['project tasks', ['responsible', 'due']];
    }

    /**
     * @param list<string> $pinned
     */
    #[DataProvider('lists')]
    public function testEachListPinsItsQuickFiltersInOrder(string $list, array $pinned): void
    {
        self::assertSame($pinned, $this->list($list)->pinned);
    }

    private function list(string $list): ListDefinition
    {
        return match ($list) {
            'notifications' => self::getContainer()->get(NotificationLists::class)->list(),
            'project tasks' => self::getContainer()->get(ProjectTaskLists::class)->list(),
            default => throw new \LogicException($list),
        };
    }
}
