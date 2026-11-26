<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Infrastructure\Directory;

use Foresi\Shared\Contract\RefusalKind;
use Foresi\Shared\Contract\Refused;
use Foresi\Shared\Domain\Id;
use Foresi\Tests\Work\Application\WorkTestCase;
use Foresi\Work\Application\ManageSharing;
use Foresi\Work\Contract\TaskDirectory;
use Foresi\Work\Contract\TaskIntake;
use Foresi\Work\Contract\TaskSummary;
use Foresi\Work\Domain\ProjectVisibility;
use Foresi\Work\Domain\Task;

/**
 * Work's tasks for other modules: made through TaskIntake with Work's own
 * checks, read back through TaskDirectory as the person signed in sees them.
 */
final class TaskDirectoryTest extends WorkTestCase
{
    private TaskIntake $intake;
    private TaskDirectory $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->intake = self::getContainer()->get(TaskIntake::class);
        $this->directory = self::getContainer()->get(TaskDirectory::class);
    }

    public function testASummaryTellsWhereTheTaskStands(): void
    {
        $website = $this->project();
        $id = $this->intake->create($website->id(), 'Send the minutes', $this->member->id(), new \DateTimeImmutable('2026-11-30'));

        $summary = $this->directory->summaries([$id])[$id] ?? null;

        self::assertInstanceOf(TaskSummary::class, $summary);
        self::assertSame('Send the minutes', $summary->title);
        self::assertSame('open', $summary->statusType);
        self::assertSame($this->statuses($website)[0]->name(), $summary->statusName);
        self::assertSame($this->member->id(), $summary->responsibleId);
        self::assertSame('Marek Member', $summary->responsibleName);
        self::assertSame('2026-11-30', $summary->dueOn?->format('Y-m-d'));
        self::assertFalse($summary->done);
        self::assertFalse($summary->trashed);
        self::assertSame('/tasks/'.$id, $summary->url);
    }

    public function testThePersonReadsOnlyTheTasksTheySeeTheTrashedOnesFlagged(): void
    {
        $sharing = self::getContainer()->get(ManageSharing::class);
        $website = $this->project();
        $open = $this->intake->create($website->id(), 'Write the brief');
        $private = $this->intake->create($website->id(), 'Agree the price', private: true);
        $trashed = $this->intake->create($website->id(), 'Old idea');
        $this->tasks->delete($trashed);
        $chosen = $this->projects->create('Offer', null);
        $offer = $this->intake->create($chosen->id(), 'Send the offer');
        $sharing->changeVisibility($chosen->id(), ProjectVisibility::Chosen);
        $ids = [$open, $private, $trashed, $offer, Id::generate(), 'not-an-id'];

        self::assertEqualsCanonicalizing([$open, $private, $trashed, $offer], array_keys($this->directory->summaries($ids)));

        $this->signIn($this->member);
        $seen = $this->directory->summaries($ids);
        self::assertEqualsCanonicalizing([$open, $trashed], array_keys($seen));
        self::assertTrue($seen[$trashed]->trashed);
        self::assertFalse($seen[$open]->trashed);

        $this->signIn($this->guest);
        self::assertSame([], $this->directory->summaries($ids));
    }

    public function testManyTasksAreReadInOneQuery(): void
    {
        $website = $this->project();
        $ids = [];
        for ($i = 1; $i <= 5; ++$i) {
            $ids[] = $this->intake->create($website->id(), 'Task '.$i, $this->member->id());
        }
        $this->em->clear();
        $this->signIn($this->member);
        $before = \count($this->queries());
        $this->directory->summaries([$ids[0]]);
        $one = \count($this->queries()) - $before;
        $before = \count($this->queries());

        $summaries = $this->directory->summaries($ids);

        // The person's scope, then the tasks: as many queries for five tasks as for one, one of them of the tasks.
        $queries = \array_slice($this->queries(), $before);
        self::assertCount(5, $summaries);
        self::assertCount($one, $queries);
        self::assertCount(1, array_filter($queries, static fn (string $sql): bool => 1 === preg_match('/\\bFROM task\\b/', $sql)));
        self::assertSame([], $this->directory->summaries([]));
    }

    public function testAPrivateTaskIsSeenByItsCreatorAndNotByTheOthers(): void
    {
        $website = $this->project();
        $this->signIn($this->member);
        $id = $this->intake->create($website->id(), 'Agree the price', private: true);

        self::assertArrayHasKey($id, $this->directory->summaries([$id]));
        $task = $this->em->find(Task::class, $id);
        self::assertInstanceOf(Task::class, $task);
        self::assertTrue($task->isPrivate());
    }

    public function testTheTaskIsRefusedWithWorksReasons(): void
    {
        $sharing = self::getContainer()->get(ManageSharing::class);
        $chosen = $this->projects->create('Offer', null);
        $sharing->changeVisibility($chosen->id(), ProjectVisibility::Chosen);
        $website = $this->project();

        $this->signIn($this->member);
        $this->assertRefused(fn (): string => $this->intake->create($chosen->id(), 'Send the offer'), 'not_found', RefusalKind::NotFound);
        $this->assertRefused(fn (): string => $this->intake->create($website->id(), ''), 'name', RefusalKind::Invalid, 'title');
        $this->assertRefused(fn (): string => $this->intake->create($website->id(), 'Call', $this->guest->id()), 'responsible', RefusalKind::Invalid, 'responsibleId');
    }

    public function testTheTaskIsSavedWithTheCallersTransactionOrNotAtAll(): void
    {
        $website = $this->project();
        try {
            $this->em->wrapInTransaction(function () use ($website): never {
                $this->intake->create($website->id(), 'Agree the price', private: true);
                throw new \RuntimeException('The caller failed.');
            });
        } catch (\RuntimeException) {
        }

        self::assertSame([], $this->em->getConnection()->fetchFirstColumn('SELECT id FROM task'));
    }

    private function assertRefused(\Closure $create, string $reason, RefusalKind $kind, ?string $field = null): void
    {
        try {
            $create();
            self::fail('The task was not refused: '.$reason);
        } catch (Refused $refused) {
            self::assertSame($reason, $refused->reason());
            self::assertSame($kind, $refused->kind());
            self::assertSame($field, $refused->field());
        }
    }

    /**
     * @return list<string> the SQL of every query run so far
     */
    private function queries(): array
    {
        /** @var list<array{sql: string}> $queries */
        $queries = self::getContainer()->get('doctrine.debug_data_holder')->getData()['default'] ?? [];

        return array_map(static fn (array $query): string => $query['sql'], $queries);
    }
}
