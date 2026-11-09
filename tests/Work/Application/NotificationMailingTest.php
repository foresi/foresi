<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Work\Application;

use App\Identity\Application\People;
use App\Identity\Domain\Membership;
use App\Shared\Application\SystemMail;
use App\Tenancy\Application\TenantClock;
use App\Work\Application\Comments;
use App\Work\Application\ManageComments;
use App\Work\Application\ManageNotificationPreferences;
use App\Work\Application\ManageNotifications;
use App\Work\Application\ManageSharing;
use App\Work\Application\NotificationMailer;
use App\Work\Application\NotificationMailing;
use App\Work\Application\NotificationPreferences;
use App\Work\Application\Notifications;
use App\Work\Application\SendNotificationDigestsHourly;
use App\Work\Application\SendNotificationMail;
use App\Work\Application\WorkUnit;
use App\Work\Application\WorkVisibility;
use App\Work\Domain\EmailDelivery;
use App\Work\Domain\Notification;
use App\Work\Domain\NotificationKind;
use App\Work\Domain\NotificationPreference;
use App\Work\Domain\Project;
use App\Work\Domain\Task;
use App\Work\Domain\WorkflowTemplate;
use App\Work\Infrastructure\Messenger\SendNotificationMailHandler;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The e-mails of notifications in an organisation in Prague: Olga Owner acts,
 * Marek Member hears. 10 November 2026 is winter time, so 08:00 in Prague is
 * 07:00 UTC.
 */
final class NotificationMailingTest extends WorkTestCase
{
    private ManageComments $comments;
    private ManageSharing $sharing;
    private NotificationMailing $mailing;
    private Project $project;
    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        $container = self::getContainer();
        $this->comments = $container->get(ManageComments::class);
        $this->sharing = $container->get(ManageSharing::class);
        $this->mailing = $container->get(NotificationMailing::class);
        $this->project = $this->project();
        $this->task = $this->tasks->create($this->project->id(), 'Fix the contact form');
        $this->transport('async')->reset();
        $this->transport('mail')->reset();
    }

    public function testAMentionIsQueuedForAnEMailTwoMinutesLater(): void
    {
        $comment = $this->comments->add($this->task->id(), sprintf('%s can you check?', $this->mention($this->member)));

        $sent = $this->transport('async')->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(SendNotificationMail::class, $message);
        self::assertSame($this->only($this->member)->id(), $message->notificationId);
        self::assertSame('mentioned', $message->kind);
        self::assertSame($this->owner->id(), $message->actorId);
        self::assertSame($comment->id(), $message->commentId);
        self::assertSame(120000, $sent[0]->last(DelayStamp::class)?->getDelay());
    }

    public function testBecomingResponsibleIsQueuedWithoutAComment(): void
    {
        $this->tasks->assign($this->task->id(), $this->member->id());

        $message = $this->onlyQueued();
        self::assertSame('assigned', $message->kind);
        self::assertNull($message->commentId);
    }

    public function testNewsOfAWatchedTaskWaitsForTheDigest(): void
    {
        $this->tasks->watch($this->task->id(), $this->member->id());
        $this->comments->add($this->task->id(), 'Started on it');
        $this->sharing->shareTask($this->tasks->create($this->project->id(), 'Other')->id(), $this->guest->id());

        self::assertSame([], $this->transport('async')->getSent());
    }

    public function testNothingIsQueuedForWhoWantsItInTheDigestOrNoEMail(): void
    {
        $this->prefer($this->member, [NotificationKind::Mentioned->value => EmailDelivery::Digest]);
        $this->comments->add($this->task->id(), $this->mention($this->member));
        self::assertSame([], $this->transport('async')->getSent());

        $this->prefer($this->member, [], noEmail: true);
        $this->tasks->assign($this->task->id(), $this->member->id());
        self::assertSame([], $this->transport('async')->getSent());
    }

    public function testTheEMailOfAMentionSaysWhoWhereAndWhatAndIsSentOnce(): void
    {
        $this->comments->add($this->task->id(), sprintf("**%s** the form\n\nfails on submit.", $this->mention($this->member)));
        $message = $this->onlyQueued();

        // The worker's handler of the queued message.
        self::getContainer()->get(SendNotificationMailHandler::class)($message);

        $mail = $this->onlyMail();
        self::assertSame('member@example.com', $mail->to);
        self::assertSame('Mentioned in “Fix the contact form”', $mail->subject);
        self::assertSame('en', $mail->locale);
        self::assertSame('Example Organisation', $mail->organisation);
        self::assertStringContainsString('Olga Owner: Mentioned you in a comment', $mail->text);
        self::assertStringContainsString('Task: Fix the contact form', $mail->text);
        self::assertStringContainsString('Project: Website', $mail->text);
        self::assertStringContainsString("Project: Website\n\n> @Marek Member the form fails on submit.\n\nOpen the task:", $mail->text);
        self::assertStringContainsString('>@Marek Member the form fails on submit.</blockquote>', (string) $mail->html);
        self::assertStringContainsString("Open the task:\nhttp://localhost/tasks/".$this->task->id(), $mail->text);
        self::assertStringContainsString('Notification settings: http://localhost/account/notifications', $mail->text);
        self::assertMatchesRegularExpression('#Stop e-mails about mentions: http://localhost/unsubscribe/[0-9a-f-]{36}/'.$this->member->id().'/mentioned\?_hash=[A-Za-z0-9_-]+&lang=en$#', $mail->text);
        self::assertStringContainsString('href="http://localhost/tasks/'.$this->task->id().'"', (string) $mail->html);
        self::assertStringNotContainsString('<strong>', (string) $mail->html);
        self::assertMatchesRegularExpression('#^<http://localhost/unsubscribe/.+/mentioned\?_hash=.+>$#', $mail->headers['List-Unsubscribe']);
        self::assertSame('List-Unsubscribe=One-Click', $mail->headers['List-Unsubscribe-Post']);
        self::assertEquals($this->clock->now(), $this->only($this->member)->emailedAt());

        // A second message of the same row, or the same one delivered twice, sends nothing more.
        self::assertFalse($this->handle($message));
        self::assertSame([], $this->transport('mail')->getSent());
    }

    public function testTheEMailIsInTheLanguageOfTheRecipient(): void
    {
        $this->member->user()->changeLocale('cs');
        $this->em->flush();
        $this->tasks->assign($this->task->id(), $this->member->id());

        self::assertTrue($this->handle($this->onlyQueued()));

        $mail = $this->onlyMail();
        self::assertSame('Přiřazený úkol „Fix the contact form“', $mail->subject);
        self::assertSame('cs', $mail->locale);
        self::assertStringContainsString('Olga Owner: Úkol přiřazen vám', $mail->text);
        self::assertStringContainsString('Neposílat e-maily o přiřazených úkolech: http://localhost/unsubscribe/', $mail->text);
        self::assertStringNotContainsString('> ', $mail->text);
        // The layout adds the footer after one blank line.
        self::assertMatchesRegularExpression('#/assigned\?_hash=[A-Za-z0-9_-]+&lang=cs$#', $mail->text);
    }

    public function testNoEMailOfARowReadOrDoneMeanwhile(): void
    {
        $this->tasks->assign($this->task->id(), $this->member->id());
        $message = $this->onlyQueued();
        $this->signIn($this->member);
        self::getContainer()->get(ManageNotifications::class)->markRead($this->task->id());

        self::assertFalse($this->handle($message));

        $this->only($this->member)->markDone($this->clock->now());
        self::assertFalse($this->handle($message));
        self::assertSame([], $this->transport('mail')->getSent());
        self::assertNull($this->only($this->member)->emailedAt());
    }

    public function testNoEMailAboutATaskNoLongerSeenOrInTheTrash(): void
    {
        $this->tasks->assign($this->task->id(), $this->member->id());
        $message = $this->onlyQueued();

        $this->sharing->makeTaskPrivate($this->task->id(), true);
        self::assertFalse($this->handle($message));

        $this->sharing->makeTaskPrivate($this->task->id(), false);
        $this->tasks->delete($this->task->id());
        self::assertFalse($this->handle($message));
        self::assertSame([], $this->transport('mail')->getSent());
    }

    public function testNoEMailToSomeoneNoLongerActive(): void
    {
        $this->tasks->assign($this->task->id(), $this->member->id());
        $message = $this->onlyQueued();
        $this->member->deactivate($this->clock->now());
        $this->em->flush();

        self::assertFalse($this->handle($message));
        self::assertSame([], $this->transport('mail')->getSent());
    }

    public function testNoEMailWhenThePreferenceChangedMeanwhile(): void
    {
        $this->tasks->assign($this->task->id(), $this->member->id());
        $message = $this->onlyQueued();
        $this->prefer($this->member, [NotificationKind::Assigned->value => EmailDelivery::Off]);

        self::assertFalse($this->handle($message));
        self::assertNull($this->only($this->member)->emailedAt());
    }

    public function testAMessageOfNothingKnownSendsNothing(): void
    {
        $this->tasks->assign($this->task->id(), $this->member->id());
        $message = $this->onlyQueued();

        self::assertFalse($this->handle(new SendNotificationMail('0190a0a0-0000-7000-8000-000000000000', 'assigned', $this->owner->id())));
        self::assertFalse($this->handle(new SendNotificationMail($message->notificationId, 'unknown', $this->owner->id())));
        self::assertFalse($this->handle(new SendNotificationMail($message->notificationId, 'commented', $this->owner->id())));
        self::assertFalse($this->handle(new SendNotificationMail($message->notificationId, 'assigned', '0190a0a0-0000-7000-8000-000000000000')));
        self::assertSame([], $this->transport('mail')->getSent());
    }

    public function testWithoutASystemMailerNothingIsMailed(): void
    {
        $this->tasks->assign($this->task->id(), $this->member->id());
        $message = $this->onlyQueued();
        $this->watchAndComment($this->task, 'Started', '2026-11-10 05:00:00');
        $container = self::getContainer();
        $mailer = $this->createMock(NotificationMailer::class);
        $mailer->method('isConfigured')->willReturn(false);
        $mailer->expects(self::never())->method('immediate');
        $mailer->expects(self::never())->method('digest');
        $mailing = new NotificationMailing(
            $container->get(Notifications::class),
            $container->get(NotificationPreferences::class),
            $container->get(Comments::class),
            $container->get(People::class),
            $container->get(WorkVisibility::class),
            $mailer,
            $container->get(TenantClock::class),
            $this->clock,
            $container->get(WorkUnit::class),
        );

        self::assertFalse($mailing->sendImmediate($message));
        self::assertSame(0, $mailing->sendDigests(new \DateTimeImmutable('2026-11-10 07:00:00 UTC')));
        self::assertNull($this->only($this->member)->emailedAt());
    }

    public function testADeletedCommentIsMailedWithoutItsText(): void
    {
        $comment = $this->comments->add($this->task->id(), sprintf('%s secret plan', $this->mention($this->member)));
        $message = $this->onlyQueued();
        $this->comments->delete($comment->id());

        self::assertTrue($this->handle($message));
        self::assertStringNotContainsString('secret plan', $this->onlyMail()->text);
    }

    public function testTheDigestComesAtTheChosenHourInTheZoneWithTheNewsOfTheDay(): void
    {
        $this->watchAndComment($this->task, 'Started on it', '2026-11-10 05:00:00');
        $other = $this->tasks->create($this->projects->create('Shop', null, WorkflowTemplate::Simple)->id(), 'Price list');
        $this->watchAndComment($other, 'New prices', '2026-11-10 05:30:00');
        $this->watchAndComment($other, 'And shipping', '2026-11-10 06:00:00');

        // 07:59 in Prague is too early, 08:00 is the default hour.
        self::assertSame(0, $this->mailing->sendDigests(new \DateTimeImmutable('2026-11-10 06:59:00 UTC')));
        self::assertSame(1, $this->mailing->sendDigests(new \DateTimeImmutable('2026-11-10 07:00:00 UTC')));

        $mail = $this->onlyMail();
        self::assertSame('member@example.com', $mail->to);
        self::assertSame('Daily digest: 2 updates', $mail->subject);
        self::assertSame('Example Organisation', $mail->organisation);
        // Grouped by project, the newest first, with the latest event and how many more.
        self::assertStringContainsString("2 updates on your tasks\n\nShop\n- Price list: Olga Owner: New comment (+1 more)\n  http://localhost/tasks/".$other->id()."\n\nWebsite\n- Fix the contact form: Olga Owner: New comment\n  http://localhost/tasks/".$this->task->id(), $mail->text);
        self::assertStringContainsString("Open notifications:\nhttp://localhost/notifications", $mail->text);
        self::assertMatchesRegularExpression('#Stop the daily digest: http://localhost/unsubscribe/.+/'.$this->member->id().'/digest\?_hash=#', $mail->text);
        self::assertStringContainsString('/digest?_hash=', $mail->headers['List-Unsubscribe']);
        self::assertNotNull($this->only($this->member)->emailedAt());
        self::assertEquals(new \DateTimeImmutable('2026-11-10 07:00:00 UTC'), $this->preference($this->member)?->lastDigestAt());

        // One a day: what is still unread is not mailed again, nor news after the hour until tomorrow.
        $this->watchAndComment($this->task, 'More', '2026-11-10 09:00:00');
        self::assertSame(0, $this->mailing->sendDigests(new \DateTimeImmutable('2026-11-10 12:00:00 UTC')));
        self::assertSame(1, $this->mailing->sendDigests(new \DateTimeImmutable('2026-11-11 07:30:00 UTC')));
        self::assertStringContainsString('Website', $this->onlyMail()->text);
    }

    public function testADigestMissedAtItsHourComesLaterThatDayOnceOnly(): void
    {
        $this->prefer($this->member, [], digestHour: 6);
        $this->watchAndComment($this->task, 'Early', '2026-11-10 04:00:00');
        $this->prefer($this->member, [], digestHour: 7);

        self::assertSame(1, $this->mailing->sendDigests(new \DateTimeImmutable('2026-11-10 10:00:00 UTC')));
        $this->transport('mail')->reset();

        // Choosing a later hour the same day sends no second digest.
        $this->watchAndComment($this->task, 'Later', '2026-11-10 10:30:00');
        $this->prefer($this->member, [], digestHour: 20);
        self::assertSame(0, $this->mailing->sendDigests(new \DateTimeImmutable('2026-11-10 19:00:00 UTC')));
    }

    public function testTheDigestHoldsOnlyTheKindsChosenForItAndNothingRead(): void
    {
        $this->clock->modify('2026-11-10 05:00:00');
        $this->tasks->assign($this->task->id(), $this->member->id());
        $read = $this->tasks->create($this->project->id(), 'Read already');
        $this->tasks->assign($read->id(), $this->member->id());
        $this->signIn($this->member);
        self::getContainer()->get(ManageNotifications::class)->markRead($read->id());
        $this->signIn($this->owner);
        $status = $this->tasks->create($this->project->id(), 'Status news');
        $this->tasks->watch($status->id(), $this->member->id());
        $this->tasks->changeStatus($status->id(), $this->statuses($this->project)[1]->id());
        $this->prefer($this->member, [NotificationKind::StatusChanged->value => EmailDelivery::Off]);

        // The assignment waits for its own e-mail, the read one is not news, status changes are off.
        self::assertSame(0, $this->mailing->sendDigests(new \DateTimeImmutable('2026-11-10 07:00:00 UTC')));

        $this->prefer($this->member, [NotificationKind::Assigned->value => EmailDelivery::Digest]);
        self::assertSame(1, $this->mailing->sendDigests(new \DateTimeImmutable('2026-11-10 07:00:00 UTC')));
        $mail = $this->onlyMail();
        self::assertSame('Daily digest: 1 update', $mail->subject);
        self::assertStringContainsString('Fix the contact form: Olga Owner: Assigned the task to you', $mail->text);
        self::assertStringNotContainsString('Read already', $mail->text);
        self::assertStringNotContainsString('Status news', $mail->text);
    }

    public function testNoDigestForNoEMailSomeoneInactiveOrATaskNotSeen(): void
    {
        $this->watchAndComment($this->task, 'Started', '2026-11-10 05:00:00');
        $this->prefer($this->member, [], noEmail: true);
        self::assertSame(0, $this->mailing->sendDigests(new \DateTimeImmutable('2026-11-10 07:00:00 UTC')));

        $this->prefer($this->member, [], noEmail: false);
        $this->sharing->makeTaskPrivate($this->task->id(), true);
        self::assertSame(0, $this->mailing->sendDigests(new \DateTimeImmutable('2026-11-10 07:00:00 UTC')));

        $this->sharing->makeTaskPrivate($this->task->id(), false);
        $this->member->deactivate($this->clock->now());
        $this->em->flush();
        self::assertSame(0, $this->mailing->sendDigests(new \DateTimeImmutable('2026-11-10 07:00:00 UTC')));
        self::assertSame([], $this->transport('mail')->getSent());
        self::assertNull($this->preference($this->member)?->lastDigestAt());
    }

    public function testTheHourlyJobSendsTheDigestsDueNow(): void
    {
        $job = self::getContainer()->get(SendNotificationDigestsHourly::class);
        self::assertSame('notification-digest', $job->name());
        self::assertSame('2026-11-10T07', $job->period(new \DateTimeImmutable('2026-11-10 08:30:00', new \DateTimeZone('Europe/Prague')), new \DateTimeZone('Europe/Prague')));

        $this->watchAndComment($this->task, 'Started', '2026-11-10 05:00:00');
        $this->clock->modify('2026-11-10 07:05:00');
        $job->run(null);

        self::assertSame('member@example.com', $this->onlyMail()->to);
    }

    private function watchAndComment(Task $task, string $text, string $at): void
    {
        $this->clock->modify($at);
        $this->tasks->watch($task->id(), $this->member->id());
        $this->comments->add($task->id(), $text);
    }

    /**
     * @param array<string, EmailDelivery> $deliveries
     */
    private function prefer(Membership $membership, array $deliveries, ?int $digestHour = null, bool $noEmail = false): void
    {
        $this->signIn($membership);
        $manage = self::getContainer()->get(ManageNotificationPreferences::class);
        $manage->save($deliveries, $digestHour ?? $manage->mine()->digestHour(), $noEmail);
        $this->signIn($this->owner);
    }

    private function preference(Membership $membership): ?NotificationPreference
    {
        return $this->em->getRepository(NotificationPreference::class)->findOneBy(['membership' => $membership]);
    }

    private function handle(SendNotificationMail $message): bool
    {
        return $this->mailing->sendImmediate($message);
    }

    private function onlyQueued(): SendNotificationMail
    {
        $sent = $this->transport('async')->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(SendNotificationMail::class, $message);

        return $message;
    }

    private function onlyMail(): SystemMail
    {
        $sent = $this->transport('mail')->getSent();
        self::assertCount(1, $sent);
        $mail = $sent[0]->getMessage();
        self::assertInstanceOf(SystemMail::class, $mail);
        $this->transport('mail')->reset();

        return $mail;
    }

    private function only(Membership $membership): Notification
    {
        $rows = $this->em->getRepository(Notification::class)->findBy(['recipient' => $membership, 'task' => $this->task]);
        self::assertCount(1, $rows);

        return $rows[0];
    }

    private function transport(string $name): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.'.$name);
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function mention(Membership $membership): string
    {
        return sprintf('@[%s](membership:%s)', $membership->user()->displayName(), $membership->id());
    }
}
