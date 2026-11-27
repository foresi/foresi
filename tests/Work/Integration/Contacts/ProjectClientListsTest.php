<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Integration\Contacts;

use Foresi\Shared\Application\View\FieldType;
use Foresi\Work\Application\View\MyTaskLists;
use Foresi\Work\Application\View\ProjectLists;
use Foresi\Work\Application\View\TaskLists;
use Foresi\Work\Contract\ProjectClients;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Where the address book is installed, the client of a project is a field of the projects
 * index, of My tasks and of the list of all tasks, a quick filter the first two pin, and a
 * grouping of the tasks.
 */
final class ProjectClientListsTest extends KernelTestCase
{
    public function testTheAddressBookAnswersTheClientsOfProjects(): void
    {
        self::assertSame('/projects/clients', self::getContainer()->get(ProjectClients::class)->pickerUrl());
    }

    public function testTheProjectsIndexFiltersByTheClientAndPinsIt(): void
    {
        $list = self::getContainer()->get(ProjectLists::class)->list();

        self::assertSame(['archived', 'client', 'visibility', 'name'], array_values(array_filter(array_keys($list->fields), static fn (string $key): bool => !str_contains($key, '_'))));
        self::assertSame(FieldType::Reference, $list->fields['client']->type);
        self::assertSame('contact', $list->fields['client']->source);
        self::assertSame('work.projects.column.client', $list->fields['client']->label);
        self::assertSame(['client'], $list->pinned);
    }

    public function testMyTasksFilterByTheClientAndPinIt(): void
    {
        $list = self::getContainer()->get(MyTaskLists::class)->list();

        self::assertSame(['involvement', 'client'], array_keys($list->fields));
        self::assertSame(['client'], $list->pinned);
    }

    public function testTheTasksFilterAndGroupByTheClient(): void
    {
        $lists = self::getContainer()->get(TaskLists::class);

        self::assertTrue($lists->hasClients());
        self::assertSame('contact', $lists->list()->fields['client']->source ?? null);
        self::assertContains('client', TaskLists::groupsOf(null, $lists->hasClients()));
    }
}
