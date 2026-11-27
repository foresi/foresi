<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Integration\Contacts;

use Foresi\Shared\Domain\View\AttributeType;
use Foresi\Ui\Contract\Attribute;
use Foresi\Work\Application\TaskRow;
use Foresi\Work\Domain\Priority;
use Foresi\Work\Domain\StatusType;
use Foresi\Work\Infrastructure\Column\MyTaskLine;
use Foresi\Work\Infrastructure\Column\ProjectColumns;
use Foresi\Work\Infrastructure\Column\ProjectIndexRow;
use Foresi\Work\Infrastructure\Column\TaskColumns;
use Foresi\Work\Infrastructure\Controller\TaskEdits;
use Foresi\Work\Integration\Contacts\ProjectClientColumns;
use PHPUnit\Framework\TestCase;

/**
 * The client column the address book adds to the project and task lists, without the lists
 * around it: the web tests of the lists reach it too, but by hundreds and slowly.
 */
final class ProjectClientColumnsTest extends TestCase
{
    public function testTheProjectListShowsTheClientAsALinkToTheContact(): void
    {
        $client = $this->only(ProjectColumns::LIST);

        self::assertSame(
            ['client', 'work.projects.column.client', AttributeType::Reference, true, 20, false, false, true],
            [$client->key, $client->label, $client->type, $client->defaultVisible, $client->defaultPosition, $client->phone, $client->wide, $client->truncate],
        );
        $linked = new ProjectIndexRow('Website', 'Example Client', '/contacts/1', 0, null);
        self::assertSame('Example Client', $client->value($linked));
        self::assertSame(['links' => [['text' => 'Example Client', 'href' => '/contacts/1']]], $client->cell($linked));
        self::assertSame(['links' => [['text' => '', 'href' => '/contacts/1']]], $client->cell(new ProjectIndexRow('Website', null, '/contacts/1', 0, null)));
        self::assertNull($client->cell(new ProjectIndexRow('Website', 'Example Client', null, 0, null)));
    }

    public function testTheTaskListShowsTheClientOnAWideScreen(): void
    {
        $client = $this->only(TaskColumns::LIST);

        self::assertSame(
            ['client', 'work.tasks.field.client', AttributeType::Text, true, 25, false, true, true],
            [$client->key, $client->label, $client->type, $client->defaultVisible, $client->defaultPosition, $client->phone, $client->wide, $client->truncate],
        );
        $task = new TaskRow('task', 'project', 'Website', null, null, 'Write the brief', 'status', 'To do', StatusType::Open, null, null, null, Priority::None, [], 0, false, clientName: 'Example Client');
        $edits = new \ReflectionClass(TaskEdits::class)->newInstanceWithoutConstructor();
        self::assertSame('Example Client', $client->value(new MyTaskLine($task, $edits)));
    }

    public function testOtherListsGetNoColumn(): void
    {
        self::assertSame([], [...new ProjectClientColumns()->attributes('contacts')]);
    }

    private function only(string $list): Attribute
    {
        $attributes = [...new ProjectClientColumns()->attributes($list)];
        self::assertCount(1, $attributes);

        return $attributes[0];
    }
}
