<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Work\Application;

use App\Identity\Domain\Membership;
use App\Shared\Domain\Id;
use App\Work\Application\ManageComments;
use App\Work\Application\ManageNotifications;
use App\Work\Application\ManageSharing;
use App\Work\Application\NotificationFilter;
use App\Work\Application\NotificationQueries;
use App\Work\Application\NotificationRow;
use App\Work\Application\Notifier;
use App\Work\Application\PurgeDeletedTasks;
use App\Work\Application\PurgeDoneNotificationsDaily;
use App\Work\Application\WorkRefused;
use App\Work\Domain\Notification;
use App\Work\Domain\NotificationKind;
use App\Work\Domain\Project;
use App\Work\Domain\ProjectVisibility;
use App\Work\Domain\Task;
use App\Work\Domain\TaskWatcher;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class NotificationsTest extends WorkTestCase
{
    private ManageComments $comments;

    private ManageSharing $sharing;

    private ManageNotifications $inbox;

    private NotificationQueries $notifications;

    private Project $project;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        $container = self::getContainer();
        $this->comments = $container->get(ManageComments::class);
        $this->sharing = $container->get(ManageSharing::class);
        $this->inbox = $container->get(ManageNotifications::class);
        $this->notifications = $container->get(NotificationQueries::class);
        $this->project = $this->project();
        $this->task = $this->tasks->create($this->project->id(), 'Write the brief');
    }

    public function testACommentTellsTheWatchersButNotItsAuthor(): void
    {
        $this->signIn($this->member);
        $this->comments->add($this->task->id(), 'Started on it');

        self::assertSame([['Write the brief', 'Website', 'commented', 'Marek Member', false, 1]], $this->rows($this->owner));
        self::assertSame([], $this->rows($this->member));
        self::assertSame(1, $this->notifications->unreadCount($this->owner));
        self::assertSame(0, $this->notifications->unreadCount($this->member));
        self::assertFalse($this->notifications->inbox($this->owner)->rows[0]->done);
    }

    public function testAMentionTellsTheMentionedOnceAndMakesThemWatch(): void
    {
        $this->comments->add($this->task->id(), sprintf('%s please, cc %s', $this->mention($this->member), $this->mention($this->owner)));

        self::assertSame([['Write the brief', 'Website', 'mentioned', 'Olga Owner', false, 1]], $this->rows($this->member));
        self::assertSame([], $this->rows($this->owner));
        self::assertSame(['Marek Member', 'Olga Owner'], $this->watchers());
        self::assertTrue($this->only($this->member)->hasDirectUnread());
    }

    public function testAWatcherMentionedHearsOfTheMentionAlone(): void
    {
        $this->tasks->watch($this->task->id(), $this->member->id());
        $this->comments->add($this->task->id(), sprintf('%s please', $this->mention($this->member)));

        self::assertSame([['Write the brief', 'Website', 'mentioned', 'Olga Owner', false, 1]], $this->rows($this->member));
    }

    public function testMentioningOneselfIsNoNews(): void
    {
        $this->signIn($this->member);
        $this->comments->add($this->task->id(), sprintf('Note to %s', $this->mention($this->member)));

        self::assertSame(['Marek Member', 'Olga Owner'], $this->watchers());
        self::assertSame(0, $this->countRows($this->member));
    }

    public function testAnEditTellsOnlyThePeopleItMentionsAnew(): void
    {
        $this->tasks->unwatch($this->task->id(), $this->owner->id());
        $this->signIn($this->member);
        $comment = $this->comments->add($this->task->id(), 'Draft');
        self::assertSame(0, $this->countRows($this->owner));

        $this->comments->edit($comment->id(), sprintf('Draft for %s', $this->mention($this->owner)));
        self::assertSame([['Write the brief', 'Website', 'mentioned', 'Marek Member', false, 1]], $this->rows($this->owner));
        self::assertSame(['Marek Member', 'Olga Owner'], $this->watchers());

        $this->signIn($this->owner);
        $this->inbox->markAllRead();
        $this->signIn($this->member);
        $this->comments->edit($comment->id(), sprintf('Final for %s', $this->mention($this->owner)));
        self::assertSame(0, $this->notifications->unreadCount($this->owner));
    }

    public function testAGuestHearsOfAPublicCommentButNeverOfAnInternalOne(): void
    {
        $this->sharing->shareTask($this->task->id(), $this->guest->id());
        $this->tasks->watch($this->task->id(), $this->guest->id());
        $this->signIn($this->guest);
        $this->inbox->markAllRead();

        $this->signIn($this->member);
        $this->comments->add($this->task->id(), sprintf('Internal, about %s', $this->mention($this->guest)));
        self::assertSame(0, $this->notifications->unreadCount($this->guest));

        $this->comments->add($this->task->id(), 'For the client too', false);
        self::assertSame([['Write the brief', 'Website', 'commented', 'Marek Member', false, 1]], $this->rows($this->guest));
    }

    public function testAnInternalCommentStillTellsTheStaffWhoWatch(): void
    {
        $this->signIn($this->member);
        $this->comments->add($this->task->id(), 'Internal note');

        self::assertSame(NotificationKind::Commented, $this->only($this->owner)->kind());
    }

    public function testBecomingResponsibleTellsThePersonOnceUnlessTheyDidItThemselves(): void
    {
        $this->tasks->assign($this->task->id(), $this->member->id());
        $this->tasks->assign($this->task->id(), $this->member->id());
        self::assertSame([['Write the brief', 'Website', 'assigned', 'Olga Owner', false, 1]], $this->rows($this->member));
        self::assertTrue($this->only($this->member)->wasAssigned());

        $this->tasks->assign($this->task->id(), $this->owner->id());
        $this->tasks->assign($this->task->id(), null);
        self::assertSame([], $this->rows($this->owner));

        $this->tasks->create($this->project->id(), 'Order the print', responsibleId: $this->member->id());
        self::assertSame(['Order the print', 'Write the brief'], array_map(static fn (NotificationRow $row): string => $row->taskTitle, $this->notifications->inbox($this->member)->rows));
        $this->tasks->create($this->project->id(), 'Mine', responsibleId: $this->owner->id());
        self::assertSame(0, $this->notifications->unreadCount($this->owner));
    }

    public function testAStatusChangeTellsTheWatchers(): void
    {
        $this->tasks->watch($this->task->id(), $this->member->id());
        [$open, $done] = [$this->statuses($this->project)[0], $this->statuses($this->project)[1]];

        $this->tasks->changeStatus($this->task->id(), $open->id());
        self::assertSame(0, $this->notifications->unreadCount($this->member));

        $this->tasks->changeStatus($this->task->id(), $done->id());
        self::assertSame([['Write the brief', 'Website', 'status_changed', 'Olga Owner', false, 1]], $this->rows($this->member));
        self::assertSame([], $this->rows($this->owner));

        $this->tasks->move($this->task->id(), $done->id());
        self::assertSame(1, $this->only($this->member)->events());
        $this->tasks->move($this->task->id(), $open->id());
        self::assertSame(2, $this->only($this->member)->events());
    }

    public function testAnUnwatchSticksUntilThePersonTakesPartAgain(): void
    {
        $this->tasks->watch($this->task->id(), $this->member->id());
        $this->tasks->unwatch($this->task->id(), $this->member->id());
        $this->tasks->changeStatus($this->task->id(), $this->statuses($this->project)[1]->id());
        self::assertSame(0, $this->notifications->unreadCount($this->member));

        $this->signIn($this->member);
        $this->comments->add($this->task->id(), 'Back on it');
        $this->signIn($this->owner);
        $this->tasks->changeStatus($this->task->id(), $this->statuses($this->project)[0]->id());
        self::assertSame(1, $this->notifications->unreadCount($this->member));
    }

    public function testSharingATaskTellsThePersonButSharingAProjectDoesNot(): void
    {
        $this->sharing->shareTask($this->task->id(), $this->guest->id());
        $this->sharing->shareTask($this->task->id(), $this->guest->id());
        self::assertSame([['Write the brief', 'Website', 'shared', 'Olga Owner', false, 1]], $this->rows($this->guest));

        $other = $this->tasks->create($this->project->id(), 'Other');
        $this->sharing->shareProject($this->project->id(), $this->member->id());
        $this->sharing->shareTask($other->id(), $this->owner->id());
        self::assertSame(0, $this->notifications->unreadCount($this->member));
        self::assertSame(0, $this->notifications->unreadCount($this->owner));
    }

    public function testNobodyHearsOfATaskTheyDoNotSee(): void
    {
        $this->tasks->watch($this->task->id(), $this->member->id());
        $this->sharing->makeTaskPrivate($this->task->id(), true);
        $this->tasks->changeStatus($this->task->id(), $this->statuses($this->project)[1]->id());
        self::assertSame(0, $this->countRows($this->member));

        $this->tasks->watch($this->task->id(), $this->owner->id());
        $this->signIn($this->member);
        $this->comments->add($this->tasks->create($this->project->id(), 'Seen')->id(), 'Hi');
        self::assertSame(0, $this->countRows($this->member));
    }

    public function testADeactivatedPersonHearsNothingAndSeesNoRow(): void
    {
        $this->tasks->watch($this->task->id(), $this->member->id());
        $this->tasks->changeStatus($this->task->id(), $this->statuses($this->project)[1]->id());
        $this->member->deactivate($this->clock->now());
        $this->em->flush();
        $this->inbox->markAllRead();
        $this->tasks->changeStatus($this->task->id(), $this->statuses($this->project)[0]->id());

        self::assertSame(1, $this->only($this->member)->events());
        self::assertSame(0, $this->notifications->unreadCount($this->member));
        self::assertSame([], $this->rows($this->member));
    }

    public function testNothingIsToldWithoutSomeoneSignedIn(): void
    {
        self::getContainer()->get(TokenStorageInterface::class)->setToken(null);
        self::getContainer()->get(Notifier::class)->assigned($this->task, $this->member);
        $this->em->flush();

        self::assertSame(0, $this->countRows($this->member));
        $this->inbox->markAllRead();
        $this->inbox->markRead($this->task->id());
        try {
            $this->inbox->markDone(Id::generate());
            self::fail('Refused.');
        } catch (WorkRefused $refused) {
            self::assertSame('not_allowed', $refused->reason);
        }
    }

    public function testEventsOfATaskFoldIntoOneRowUntilItIsDone(): void
    {
        $this->signIn($this->member);
        $this->comments->add($this->task->id(), 'One');
        $this->clock->modify('+1 minute');
        $this->comments->add($this->task->id(), 'Two');
        self::assertSame([['Write the brief', 'Website', 'commented', 'Marek Member', false, 2]], $this->rows($this->owner));
        self::assertSame('2026-11-10 10:01', $this->notifications->inbox($this->owner)->rows[0]->at->format('Y-m-d H:i'));

        $this->signIn($this->owner);
        $this->inbox->markRead($this->task->id());
        self::assertSame([['Write the brief', 'Website', 'commented', 'Marek Member', true, 2]], $this->rows($this->owner));
        self::assertSame(0, $this->notifications->unreadCount($this->owner));

        $this->signIn($this->member);
        $this->comments->add($this->task->id(), 'Three');
        self::assertSame([['Write the brief', 'Website', 'commented', 'Marek Member', false, 1]], $this->rows($this->owner));

        $this->signIn($this->owner);
        $row = $this->only($this->owner);
        $this->inbox->markDone($row->id());
        self::assertSame([], $this->rows($this->owner));
        self::assertSame([['Write the brief', 'Website', 'commented', 'Marek Member', true, 1]], $this->rows($this->owner, done: true));
        self::assertTrue($this->notifications->inbox($this->owner, done: true)->rows[0]->done);

        $this->signIn($this->member);
        $this->comments->add($this->task->id(), 'Four');
        self::assertSame(1, $this->notifications->unreadCount($this->owner));
        self::assertSame(2, $this->countRows($this->owner));

        // The newer open row shows the task, so the done one stays done.
        $this->signIn($this->owner);
        $this->inbox->markUndone($row->id());
        self::assertCount(1, $this->notifications->inbox($this->owner, done: true)->rows);
    }

    public function testADoneRowComesBackWhenNothingNewerIsOpen(): void
    {
        $this->signIn($this->member);
        $this->comments->add($this->task->id(), 'One');
        $this->signIn($this->owner);
        $row = $this->only($this->owner);
        $this->inbox->markDone($row->id());
        $this->inbox->markUndone($row->id());
        $this->inbox->markUndone($row->id());

        self::assertSame([['Write the brief', 'Website', 'commented', 'Marek Member', true, 1]], $this->rows($this->owner));
        self::assertSame([], $this->rows($this->owner, done: true));
    }

    public function testNobodyTouchesTheInboxOfSomeoneElse(): void
    {
        $this->signIn($this->member);
        $this->comments->add($this->task->id(), 'One');
        $row = $this->only($this->owner);

        foreach ([fn () => $this->inbox->markDone($row->id()), fn () => $this->inbox->markUndone($row->id()), fn () => $this->inbox->markDone('nope')] as $attempt) {
            try {
                $attempt();
                self::fail('Refused.');
            } catch (WorkRefused $refused) {
                self::assertSame('unknown', $refused->reason);
            }
        }
        $this->inbox->markRead($this->task->id());
        $this->inbox->markRead('nope');
        $this->inbox->markAllRead();
        self::assertSame(1, $this->notifications->unreadCount($this->owner));
        self::assertFalse($row->isDone());
    }

    public function testMarkingAllReadReadsEveryOpenRowOfThePerson(): void
    {
        $other = $this->tasks->create($this->project->id(), 'Other');
        $this->signIn($this->member);
        $this->comments->add($this->task->id(), 'One');
        $this->comments->add($other->id(), 'Two');
        $this->comments->add($this->task->id(), sprintf('cc %s', $this->mention($this->guest)));
        self::assertSame(2, $this->notifications->unreadCount($this->owner));

        $this->signIn($this->owner);
        $this->inbox->markAllRead();
        self::assertSame(0, $this->notifications->unreadCount($this->owner));
        self::assertSame([true, true], array_map(static fn (NotificationRow $row): bool => $row->read, $this->notifications->inbox($this->owner)->rows));
    }

    public function testTheFiltersShowRowsAMentionOrAnAssignmentFoldedInto(): void
    {
        $mentioned = $this->tasks->create($this->project->id(), 'Mentioned');
        $assigned = $this->tasks->create($this->project->id(), 'Assigned');
        $this->tasks->watch($this->task->id(), $this->member->id());
        $this->comments->add($mentioned->id(), sprintf('%s look', $this->mention($this->member)));
        $this->clock->modify('+1 minute');
        $this->tasks->assign($assigned->id(), $this->member->id());
        $this->clock->modify('+1 minute');
        $this->tasks->changeStatus($this->task->id(), $this->statuses($this->project)[1]->id());
        $this->clock->modify('+1 minute');
        // A later comment keeps the row among the mentions.
        $this->comments->add($mentioned->id(), 'More');

        self::assertSame(['Mentioned', 'Write the brief', 'Assigned'], $this->titles($this->member, NotificationFilter::All));
        self::assertSame(['Mentioned'], $this->titles($this->member, NotificationFilter::Mentions));
        self::assertSame(['Assigned'], $this->titles($this->member, NotificationFilter::Assigned));
        self::assertSame(NotificationKind::Commented, $this->notifications->inbox($this->member)->rows[0]->kind);
        self::assertSame([], $this->notifications->inbox($this->member, NotificationFilter::Mentions, true)->rows);
    }

    public function testTheInboxGoesPageByPage(): void
    {
        for ($i = 0; $i <= NotificationQueries::PAGE_SIZE; ++$i) {
            $this->tasks->create($this->project->id(), sprintf('Task %02d', $i), responsibleId: $this->member->id());
            $this->clock->modify('+1 second');
        }

        $first = $this->notifications->inbox($this->member);
        self::assertCount(NotificationQueries::PAGE_SIZE, $first->rows);
        self::assertTrue($first->hasNext);
        self::assertSame(1, $first->page);
        self::assertSame('Task 50', $first->rows[0]->taskTitle);
        $second = $this->notifications->inbox($this->member, page: 2);
        self::assertSame(['Task 00'], array_map(static fn (NotificationRow $row): string => $row->taskTitle, $second->rows));
        self::assertFalse($second->hasNext);
        self::assertSame(2, $second->page);
        self::assertSame(1, $this->notifications->inbox($this->member, page: 0)->page);
        self::assertSame(NotificationQueries::PAGE_SIZE + 1, $this->notifications->unreadCount($this->member));
    }

    public function testARowLeavesTheInboxWhenThePersonNoLongerSeesTheTaskAndComesBackWithIt(): void
    {
        $this->sharing->shareTask($this->task->id(), $this->guest->id());
        self::assertSame(1, $this->notifications->unreadCount($this->guest));

        $this->sharing->unshareTask($this->task->id(), $this->guest->id());
        self::assertSame(0, $this->notifications->unreadCount($this->guest));
        self::assertSame([], $this->rows($this->guest));
        self::assertSame(1, $this->countRows($this->guest));

        $this->sharing->shareTask($this->task->id(), $this->guest->id());
        self::assertSame(1, $this->notifications->unreadCount($this->guest));
    }

    public function testARowOfAPrivateTaskOrOfOneInTheTrashIsNotShown(): void
    {
        $this->tasks->assign($this->task->id(), $this->member->id());
        $other = $this->tasks->create($this->project->id(), 'Other', responsibleId: $this->member->id());
        $this->sharing->changeVisibility($this->project->id(), ProjectVisibility::Chosen);
        self::assertSame(0, $this->notifications->unreadCount($this->member));

        $this->sharing->changeVisibility($this->project->id(), ProjectVisibility::Organisation);
        $this->sharing->makeTaskPrivate($this->task->id(), true);
        $this->tasks->delete($other->id());
        self::assertSame(0, $this->notifications->unreadCount($this->member));
        self::assertSame([], $this->rows($this->member));
        self::assertSame(2, $this->countRows($this->member));
    }

    public function testTheDoneRowsGoAfterThreeMonths(): void
    {
        $job = self::getContainer()->get(PurgeDoneNotificationsDaily::class);
        $prague = new \DateTimeZone('Europe/Prague');
        self::assertSame('notification-purge', $job->name());
        self::assertSame('2026-11-09', $job->period(new \DateTimeImmutable('2026-11-10 01:59', new \DateTimeZone('UTC')), $prague));
        self::assertSame('2026-11-10', $job->period(new \DateTimeImmutable('2026-11-10 02:00', new \DateTimeZone('UTC')), $prague));

        $other = $this->tasks->create($this->project->id(), 'Other');
        $this->tasks->assign($this->task->id(), $this->member->id());
        $this->tasks->assign($other->id(), $this->member->id());
        $this->signIn($this->member);
        $this->inbox->markDone($this->only($this->member, $this->task)->id());
        $this->clock->modify('+1 day');
        $this->inbox->markDone($this->only($this->member, $other)->id());
        $this->tasks->create($this->project->id(), 'Open', responsibleId: $this->owner->id());

        $this->clock->modify('+3 months -1 day');
        self::assertSame(0, $job->purge(\DateTimeImmutable::createFromInterface($this->clock->now())));
        $this->clock->modify('+1 second');
        $job->run(null);
        $this->em->clear();
        self::assertSame(['Other'], array_map(static fn (Notification $row): string => $row->task()->title(), $this->em->getRepository(Notification::class)->findBy(['recipient' => $this->member->id()])));
        self::assertSame(1, $this->countRows($this->owner));
    }

    public function testPurgingATaskFromTheTrashTakesItsNotifications(): void
    {
        $this->tasks->assign($this->task->id(), $this->member->id());
        $this->tasks->delete($this->task->id());
        $this->clock->modify('+31 days');
        self::getContainer()->get(PurgeDeletedTasks::class)->run(\DateTimeImmutable::createFromInterface($this->clock->now()));

        $this->em->clear();
        self::assertSame(0, $this->countRows($this->member));
    }

    /**
     * @return list<array{string, string, string, string, bool, int}>
     */
    private function rows(Membership $membership, bool $done = false): array
    {
        return array_map(static fn (NotificationRow $row): array => [$row->taskTitle, $row->projectName, $row->kind->value, $row->actorName, $row->read, $row->events], $this->notifications->inbox($membership, done: $done)->rows);
    }

    /**
     * @return list<string>
     */
    private function titles(Membership $membership, NotificationFilter $filter): array
    {
        return array_map(static fn (NotificationRow $row): string => $row->taskTitle, $this->notifications->inbox($membership, $filter)->rows);
    }

    private function only(Membership $membership, ?Task $task = null): Notification
    {
        $rows = $this->em->getRepository(Notification::class)->findBy(['recipient' => $membership, 'task' => $task ?? $this->task]);
        self::assertCount(1, $rows);

        return $rows[0];
    }

    /**
     * Every row of the person, whatever they see.
     */
    private function countRows(Membership $membership): int
    {
        return \count($this->em->getRepository(Notification::class)->findBy(['recipient' => $membership->id()]));
    }

    /**
     * @return list<string>
     */
    private function watchers(): array
    {
        $names = array_map(static fn (TaskWatcher $watcher): string => $watcher->membership()->user()->displayName(), $this->em->getRepository(TaskWatcher::class)->findBy(['task' => $this->task]));
        sort($names);

        return $names;
    }

    private function mention(Membership $membership): string
    {
        return sprintf('@[%s](membership:%s)', $membership->user()->displayName(), $membership->id());
    }
}
