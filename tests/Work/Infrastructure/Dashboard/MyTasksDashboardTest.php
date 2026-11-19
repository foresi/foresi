<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Infrastructure\Dashboard;

use Foresi\Work\Application\TaskGroup;
use Foresi\Work\Application\TaskRow;
use Foresi\Work\Application\WorkQueries;
use Foresi\Work\Infrastructure\Dashboard\MyTasksDashboard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class MyTasksDashboardTest extends TestCase
{
    public function testCountsTheDueTasksAndOpensMyTasks(): void
    {
        $sections = [...$this->dashboard([
            new TaskGroup(TaskGroup::OVERDUE, $this->tasks(2)),
            new TaskGroup(TaskGroup::UPCOMING, $this->tasks(1)),
            new TaskGroup(TaskGroup::LATER, $this->tasks(5)),
        ])->sections()];

        self::assertCount(1, $sections);
        self::assertSame('@Work/dashboard.html.twig', $sections[0]->template);
        self::assertSame([
            ['label' => 'work.my_tasks.group.overdue', 'value' => '2', 'href' => '/my-tasks?view=assigned'],
            ['label' => 'work.my_tasks.group.today', 'value' => '0', 'href' => '/my-tasks?view=assigned'],
            ['label' => 'work.my_tasks.group.upcoming', 'value' => '1', 'href' => '/my-tasks?view=assigned'],
        ], $sections[0]->context['kpis']);
    }

    public function testShowsNothingWhenNothingIsDueWithinAWeek(): void
    {
        self::assertSame([], [...$this->dashboard([new TaskGroup(TaskGroup::LATER, $this->tasks(3))])->sections()]);
    }

    /**
     * @param list<TaskGroup> $groups
     */
    private function dashboard(array $groups): MyTasksDashboard
    {
        $queries = self::createStub(WorkQueries::class);
        $queries->method('myTasks')->willReturn($groups);
        $urls = self::createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn (string $route, array $parameters = []): string => '/my-tasks?'.http_build_query($parameters));
        $translator = self::createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new MyTasksDashboard($queries, $urls, $translator);
    }

    /**
     * @return list<TaskRow>
     */
    private function tasks(int $count): array
    {
        $reflection = new \ReflectionClass(TaskRow::class);

        $tasks = [];
        for ($i = 0; $i < $count; ++$i) {
            $tasks[] = $reflection->newInstanceWithoutConstructor();
        }

        return $tasks;
    }
}
