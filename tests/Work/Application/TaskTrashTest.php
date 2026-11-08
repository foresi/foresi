<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Work\Application;

use App\Attachment\Application\AttachmentFiles;
use App\Attachment\Application\AttachmentFileUnreadable;
use App\Attachment\Application\AttachmentPermissions;
use App\Attachment\Domain\Attachment;
use App\Audit\Domain\AuditAction;
use App\Shared\Domain\Id;
use App\Tenancy\Application\TenantContext;
use App\Work\Application\ManageComments;
use App\Work\Application\PurgeDeletedTasks;
use App\Work\Application\PurgeTaskTrashDaily;
use App\Work\Application\TaskRow;
use App\Work\Application\TrashedTask;
use App\Work\Application\WorkRefused;
use App\Work\Domain\LabelColour;
use App\Work\Domain\Project;
use App\Work\Domain\Task;
use App\Work\Domain\TaskComment;
use App\Work\Infrastructure\Attachment\TaskAttachmentAccess;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * The trash of tasks: what goes there together comes back together, only the
 * author or an owner moves a task there and back, nothing in it shows anywhere
 * but in the trash, and after 30 days it goes for good with all it had.
 */
final class TaskTrashTest extends WorkTestCase
{
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = $this->project();
    }

    public function testDeletingATaskPutsItsSubtasksInTheTrashAtTheSameMomentAsOneStep(): void
    {
        $task = $this->tasks->create($this->project->id(), 'Launch');
        $first = $this->tasks->create($this->project->id(), 'Check the links', parentId: $task->id());
        $second = $this->tasks->create($this->project->id(), 'Tell the client', parentId: $task->id());
        $this->clock->sleep(60);

        $this->tasks->delete($task->id());

        foreach ([$task, $first, $second] as $one) {
            self::assertSame(['2026-11-10 09:01', $this->owner->user()->id()], [$one->deletedAt()?->format('Y-m-d H:i'), $one->deletedBy()]);
        }
        $correlations = $this->em->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT correlation_id FROM audit_entry WHERE entity_class = ? AND action = ?',
            [Task::class, AuditAction::Updated->value],
        );
        self::assertCount(1, $correlations);
        // Gone for every command too.
        $this->assertRefused('unknown', fn () => $this->tasks->rename($task->id(), 'Again'));
        $this->assertRefused('unknown', fn (): Task => $this->tasks->delete($first->id()));
    }

    public function testATaskInTheTrashShowsNowhere(): void
    {
        $label = $this->labels->create('Design', LabelColour::Green);
        $kept = $this->tasks->create($this->project->id(), 'Kept', responsibleId: $this->owner->id(), dueOn: new \DateTimeImmutable('2026-11-20'));
        $gone = $this->tasks->create($this->project->id(), 'Gone', responsibleId: $this->owner->id(), dueOn: new \DateTimeImmutable('2026-11-09'));
        $this->tasks->setLabels($gone->id(), [$label->id()]);
        $subtask = $this->tasks->create($this->project->id(), 'Gone too', parentId: $kept->id(), responsibleId: $this->owner->id(), dueOn: new \DateTimeImmutable('2026-11-01'));
        $this->tasks->create($this->project->id(), 'Stays', parentId: $kept->id());
        self::assertSame(2, $this->queries->myDueCount());

        $this->tasks->delete($gone->id());
        $this->tasks->delete($subtask->id());

        $list = $this->queries->projectList($this->project->id());
        self::assertNotNull($list);
        self::assertSame([['Kept', ['Stays']]], $this->titles($list->sections[0]->tasks));
        $board = $this->queries->board($this->project->id());
        self::assertNotNull($board);
        self::assertSame([['Kept', ['Stays']]], $this->titles($board->columns[0]->tasks));
        self::assertSame(['Kept'], array_map(static fn (TaskRow $row): string => $row->title, [...$this->queries->myTasks()[0]->tasks, ...$this->queries->myTasks()[1]->tasks, ...$this->queries->myTasks()[2]->tasks, ...$this->queries->myTasks()[3]->tasks]));
        self::assertSame(['Kept', 'Stays'], array_map(static fn (TaskRow $row): string => $row->title, $this->queries->watchedTasks()));
        self::assertSame(1, $this->queries->watchedTasks()[0]->subtaskCount);
        self::assertSame(0, $this->queries->myDueCount());
        $row = $this->queries->projects()[0];
        self::assertSame([2, '2026-11-20'], [$row->openTasks, $row->nearestDue?->format('Y-m-d')]);
        $detail = $this->queries->task($kept->id());
        self::assertNotNull($detail);
        self::assertSame([1, ['Stays']], [$detail->task->subtaskCount, array_map(static fn (TaskRow $row): string => $row->title, $detail->task->subtasks)]);
        self::assertSame([], $this->queries->labelUsage());
        foreach ([$gone, $subtask] as $task) {
            self::assertNull($this->queries->task($task->id()));
            self::assertNull($this->queries->discussion($task->id()));
            self::assertNull($this->queries->mentionCandidates($task->id()));
        }
    }

    public function testRestoringBringsBackWhatWentToTheTrashAtThatMoment(): void
    {
        $task = $this->tasks->create($this->project->id(), 'Launch');
        $earlier = $this->tasks->create($this->project->id(), 'Deleted before', parentId: $task->id());
        $with = $this->tasks->create($this->project->id(), 'Deleted with it', parentId: $task->id());
        $this->tasks->delete($earlier->id());
        $this->clock->sleep(60);
        $this->tasks->delete($task->id());

        $this->tasks->restore($task->id());

        self::assertSame([false, false, true], [$task->isDeleted(), $with->isDeleted(), $earlier->isDeleted()]);
        self::assertNull($task->deletedBy());
        // Restoring what is not in the trash changes nothing.
        $this->tasks->restore($task->id());
        self::assertFalse($task->isDeleted());
    }

    public function testRestoringASubtaskBringsBackItsTaskAndWhatWentWithIt(): void
    {
        $task = $this->tasks->create($this->project->id(), 'Launch');
        $alone = $this->tasks->create($this->project->id(), 'Deleted alone', parentId: $task->id());
        $other = $this->tasks->create($this->project->id(), 'Deleted with the task', parentId: $task->id());
        $this->tasks->delete($alone->id());
        $this->clock->sleep(60);
        $this->tasks->delete($task->id());

        $this->tasks->restore($alone->id());

        self::assertSame([false, false, false], [$task->isDeleted(), $alone->isDeleted(), $other->isDeleted()]);
        $detail = $this->queries->task($task->id());
        self::assertNotNull($detail);
        self::assertSame(2, $detail->task->subtaskCount);
    }

    public function testARestoredTaskKeepsItsPlace(): void
    {
        $this->tasks->create($this->project->id(), 'First');
        $second = $this->tasks->create($this->project->id(), 'Second');
        $this->tasks->create($this->project->id(), 'Third');

        $this->tasks->delete($second->id());
        $this->tasks->create($this->project->id(), 'Fourth');
        $this->tasks->restore($second->id());

        $list = $this->queries->projectList($this->project->id());
        self::assertNotNull($list);
        self::assertSame([['First', []], ['Second', []], ['Third', []], ['Fourth', []]], $this->titles($list->sections[0]->tasks));
    }

    public function testOnlyTheAuthorOrAnOwnerDeletesAndRestores(): void
    {
        $ownersTask = $this->tasks->create($this->project->id(), 'The owner’s');
        $this->signIn($this->member);
        $membersTask = $this->tasks->create($this->project->id(), 'The member’s');

        $list = $this->queries->projectList($this->project->id());
        self::assertNotNull($list);
        self::assertSame(['The owner’s' => false, 'The member’s' => true], array_column(array_map(static fn (TaskRow $row): array => [$row->title, $row->deletable], $list->sections[0]->tasks), 1, 0));
        $this->assertRefused('task_not_yours', fn (): Task => $this->tasks->delete($ownersTask->id()));
        $this->tasks->delete($membersTask->id());
        $this->tasks->restore($membersTask->id());
        $this->tasks->delete($membersTask->id());

        $this->signIn($this->owner);
        $this->tasks->delete($ownersTask->id());
        $this->signIn($this->member);
        $this->assertRefused('task_not_yours', fn (): Task => $this->tasks->restore($ownersTask->id()));
        $trash = $this->queries->trash($this->project->id());
        self::assertNotNull($trash);
        self::assertSame(['The member’s' => true, 'The owner’s' => false], array_column(array_map(static fn (TrashedTask $task): array => [$task->title, $task->restorable], $trash->tasks), 1, 0));

        // An owner restores the task of anyone.
        $this->signIn($this->owner);
        $this->tasks->restore($membersTask->id());
        self::assertFalse($membersTask->isDeleted());

        // A guest sees no task, in the trash or out of it.
        $this->signIn($this->guest);
        $this->assertRefused('unknown', fn (): Task => $this->tasks->restore($ownersTask->id()));
        $this->assertRefused('unknown', fn (): Task => $this->tasks->delete($membersTask->id()));
        self::assertNull($this->queries->trash($this->project->id()));
        self::assertSame(0, $this->queries->trashCount($this->project->id()));
    }

    public function testTheTrashShowsWhatWentThereItselfTheLastFirst(): void
    {
        $task = $this->tasks->create($this->project->id(), 'Launch');
        $this->tasks->create($this->project->id(), 'With the task', parentId: $task->id());
        $alone = $this->tasks->create($this->project->id(), 'Alone', parentId: $task->id());
        $other = $this->tasks->create($this->project->id(), 'Another');
        $this->tasks->delete($alone->id());
        $this->clock->sleep(60);
        $this->tasks->delete($task->id());
        $this->clock->sleep(60);
        $this->tasks->delete($other->id());

        $trash = $this->queries->trash($this->project->id());

        self::assertNotNull($trash);
        self::assertSame([
            ['Another', null, 'Olga Owner', '09:02'],
            ['Launch', null, 'Olga Owner', '09:01'],
            ['Alone', 'Launch', 'Olga Owner', '09:00'],
        ], array_map(static fn (TrashedTask $task): array => [$task->title, $task->parentTitle, $task->deletedByName, $task->deletedAt->format('H:i')], $trash->tasks));
        self::assertSame(3, $this->queries->trashCount($this->project->id()));
    }

    public function testTheFilesOfATaskInTheTrashAndOfItsCommentsAreReachedByNobody(): void
    {
        $task = $this->tasks->create($this->project->id(), 'Launch');
        $comment = self::getContainer()->get(ManageComments::class)->add($task->id(), 'See the file');
        $permissions = self::getContainer()->get(AttachmentPermissions::class);
        self::assertTrue($permissions->canSee(TaskAttachmentAccess::KIND, $task->id()));
        self::assertTrue($permissions->canSee(TaskAttachmentAccess::COMMENT_KIND, $comment->id()));

        $this->tasks->delete($task->id());

        self::assertFalse($permissions->canSee(TaskAttachmentAccess::KIND, $task->id()));
        self::assertFalse($permissions->canSee(TaskAttachmentAccess::COMMENT_KIND, $comment->id()));
        self::assertFalse($permissions->canAttach(TaskAttachmentAccess::KIND, $task->id()));

        $this->tasks->restore($task->id());
        self::assertTrue($permissions->canSee(TaskAttachmentAccess::COMMENT_KIND, $comment->id()));
    }

    public function testThePurgeRemovesForGoodWhatIsInTheTrashForMoreThanThirtyDays(): void
    {
        $label = $this->labels->create('Design', LabelColour::Green);
        $old = $this->tasks->create($this->project->id(), 'Old', responsibleId: $this->member->id());
        $oldSubtask = $this->tasks->create($this->project->id(), 'Old subtask', parentId: $old->id());
        $this->tasks->setLabels($old->id(), [$label->id()]);
        $comments = self::getContainer()->get(ManageComments::class);
        $comment = $comments->add($oldSubtask->id(), sprintf('Look, @[Marek Member](membership:%s)', $this->member->id()));
        $comments->delete($comments->add($old->id(), 'Never mind')->id());
        $files = [$this->attach(TaskAttachmentAccess::KIND, $old->id()), $this->attach(TaskAttachmentAccess::COMMENT_KIND, $comment->id())];
        $files[] = $this->attach(TaskAttachmentAccess::KIND, $oldSubtask->id(), deleted: true);
        $alone = $this->tasks->create($this->project->id(), 'Old subtask alone', parentId: $this->tasks->create($this->project->id(), 'Active')->id());
        $young = $this->tasks->create($this->project->id(), 'Young');
        $youngFile = $this->attach(TaskAttachmentAccess::KIND, $young->id());
        $this->tasks->delete($old->id());
        $this->tasks->delete($alone->id());
        $this->clock->sleep(86400);
        $this->tasks->delete($young->id());

        $purge = self::getContainer()->get(PurgeDeletedTasks::class);
        // Thirty days to the second are not more than thirty days.
        self::assertSame(0, $purge->run($this->clock->now()->modify('+29 days')));
        self::assertSame(3, $purge->run($this->clock->now()->modify('+29 days +1 second')));
        $this->em->clear();

        $connection = $this->em->getConnection();
        self::assertSame(['Active', 'Young'], $connection->fetchFirstColumn('SELECT title FROM task ORDER BY title'));
        foreach (['task_comment', 'comment_mention', 'task_label'] as $table) {
            self::assertSame(0, $this->number('SELECT COUNT(*) FROM '.$table), $table);
        }
        self::assertSame(['Olga Owner', 'Olga Owner'], $connection->fetchFirstColumn('SELECT u.display_name FROM task_watcher w JOIN membership m ON m.id = w.membership_id JOIN app_user u ON u.id = m.user_id'));
        self::assertSame([$youngFile->id()], $connection->fetchFirstColumn('SELECT id FROM attachment'));
        $store = self::getContainer()->get(AttachmentFiles::class);
        $tenant = self::getContainer()->get(TenantContext::class)->current()->toString();
        foreach ($files as $file) {
            try {
                $store->locate($tenant, $file->storageKey());
                self::fail('The file of '.$file->fileName().' is still there.');
            } catch (AttachmentFileUnreadable) {
            }
        }
        self::assertFileExists($store->locate($tenant, $youngFile->storageKey()));
        // The log keeps what they were.
        self::assertSame(3, $this->number('SELECT COUNT(*) FROM audit_entry WHERE entity_class = ? AND action = ?', [Task::class, AuditAction::Deleted->value]));
        self::assertSame(2, $this->number('SELECT COUNT(*) FROM audit_entry WHERE entity_class = ? AND action = ?', [TaskComment::class, AuditAction::Deleted->value]));
    }

    public function testAPurgeThatFailsLeavesTheTaskInTheTrashWithItsFiles(): void
    {
        $task = $this->tasks->create($this->project->id(), 'Old');
        $file = $this->attach(TaskAttachmentAccess::KIND, $task->id());
        $this->tasks->delete($task->id());
        $failing = new class {
            public function onFlush(OnFlushEventArgs $args): void
            {
                foreach ($args->getObjectManager()->getUnitOfWork()->getScheduledEntityDeletions() as $entity) {
                    if ($entity instanceof Task) {
                        throw new \RuntimeException('The database is gone.');
                    }
                }
            }
        };
        $this->em->getEventManager()->addEventListener(Events::onFlush, $failing);
        try {
            self::getContainer()->get(PurgeDeletedTasks::class)->run($this->clock->now()->modify('+31 days'));
            self::fail('The purge should have failed.');
        } catch (\RuntimeException $failure) {
            self::assertSame('The database is gone.', $failure->getMessage());
        } finally {
            $this->em->getEventManager()->removeEventListener(Events::onFlush, $failing);
        }
        $this->em->clear();

        self::assertSame(1, $this->number('SELECT COUNT(*) FROM task WHERE deleted_at IS NOT NULL'));
        self::assertSame([$file->id()], $this->em->getConnection()->fetchFirstColumn('SELECT id FROM attachment'));
        self::assertFileExists(self::getContainer()->get(AttachmentFiles::class)->locate(self::getContainer()->get(TenantContext::class)->current()->toString(), $file->storageKey()));
    }

    public function testASubtaskDoesNotBringBackATaskThePersonMayNotRestore(): void
    {
        $task = $this->tasks->create($this->project->id(), 'The owner’s');
        $this->signIn($this->member);
        $subtask = $this->tasks->create($this->project->id(), 'The member’s', parentId: $task->id());
        $this->signIn($this->owner);
        $this->tasks->delete($task->id());

        $this->signIn($this->member);
        $this->assertRefused('parent_in_trash', fn (): Task => $this->tasks->restore($subtask->id()));
        self::assertSame([true, true], [$task->isDeleted(), $subtask->isDeleted()]);

        $this->signIn($this->owner);
        $this->tasks->restore($subtask->id());
        self::assertSame([false, false], [$task->isDeleted(), $subtask->isDeleted()]);
    }

    public function testThePurgeRunsOnceADayFromThreeInTheMorningOfTheTenant(): void
    {
        $job = new PurgeTaskTrashDaily(self::getContainer()->get(PurgeDeletedTasks::class), $this->clock);
        $prague = new \DateTimeZone('Europe/Prague');

        self::assertSame('task-trash-purge', $job->name());
        self::assertSame('2026-11-09', $job->period(new \DateTimeImmutable('2026-11-10 01:59', new \DateTimeZone('UTC')), $prague));
        self::assertSame('2026-11-10', $job->period(new \DateTimeImmutable('2026-11-10 02:00', new \DateTimeZone('UTC')), $prague));

        $task = $this->tasks->create($this->project->id(), 'Old');
        $this->tasks->delete($task->id());
        $this->clock->modify('+31 days');
        $job->run(null);
        $this->em->clear();
        self::assertSame(0, $this->number('SELECT COUNT(*) FROM task'));
    }

    /**
     * @param list<TaskRow> $rows
     *
     * @return list<array{string, list<string>}>
     */
    private function titles(array $rows): array
    {
        return array_map(static fn (TaskRow $row): array => [$row->title, array_map(static fn (TaskRow $subtask): string => $subtask->title, $row->subtasks)], $rows);
    }

    private function attach(string $kind, string $ownerId, bool $deleted = false): Attachment
    {
        $source = tempnam(sys_get_temp_dir(), 'trash');
        self::assertIsString($source);
        file_put_contents($source, 'A file of '.$ownerId);
        $id = Id::generate();
        $key = self::getContainer()->get(AttachmentFiles::class)->store(self::getContainer()->get(TenantContext::class)->current()->toString(), $id, $source);
        $attachment = new Attachment($id, $kind, $ownerId, 'notes.txt', 'text/plain', (int) filesize($source), (string) hash_file('sha256', $source), $key, $this->owner->user()->id(), $this->clock->now());
        unlink($source);
        if ($deleted) {
            $attachment->delete($this->clock->now());
        }
        $this->em->persist($attachment);
        $this->em->flush();

        return $attachment;
    }

    /**
     * @param list<string> $parameters
     */
    private function number(string $sql, array $parameters = []): int
    {
        $count = $this->em->getConnection()->fetchOne($sql, $parameters);
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function assertRefused(string $reason, callable $change): void
    {
        try {
            $change();
            self::fail(sprintf('Expected the refusal "%s".', $reason));
        } catch (WorkRefused $refused) {
            self::assertSame($reason, $refused->reason);
        }
    }
}
