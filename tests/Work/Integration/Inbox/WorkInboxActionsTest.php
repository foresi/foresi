<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Integration\Inbox;

use Foresi\Activities\Domain\ActivityTiming;
use Foresi\Activities\Domain\ActivityType;
use Foresi\Attachment\Application\VisibleAttachments;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Foresi\Inbox\Application\InboxPurpose;
use Foresi\Inbox\Domain\InboxItem;
use Foresi\Inbox\Infrastructure\Doctrine\DoctrineItemStore;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Mail\Application\ReceiveMail;
use Foresi\Mail\Application\ReceiveOutcome;
use Foresi\Mail\Contract\AddressIssuer;
use Foresi\Mail\Domain\InboundChannel;
use Foresi\Tests\Inbox\Support\InboxMail;
use Foresi\Tests\Work\Infrastructure\Api\WorkApiTestCase;
use Foresi\Work\Application\ManageProjects;
use Foresi\Work\Domain\Project;
use Foresi\Work\Infrastructure\Attachment\TaskAttachmentAccess;
use Foresi\Work\Integration\Inbox\CreateTaskFromEmailPreFill;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The inbox actions of Work: a task or time made from an inbox item with the
 * project of its client pre-selected, recorded as outputs of the item, the one
 * e-mail activity of the item linked to what they made and to the project.
 */
final class WorkInboxActionsTest extends WorkApiTestCase
{
    private string $address;
    private string $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->flushed(function (): void {
            // The type the activities migration seeds, which a schema built from the mapping lacks.
            $this->em()->persist(new ActivityType('email', null, ActivityTiming::None, null, new \DateTimeImmutable()));
        });
        $this->address = self::getContainer()->get(AddressIssuer::class)->issue(InboxPurpose::KEY, $this->user->id(), 'My inbox')->address;
        $this->agent = $this->issued([ApiScope::from('inbox:write'), ApiScope::from('tasks:write'), ApiScope::from('time_entries:write')]);
    }

    public function testCreateTaskFromEmailLinksTheTaskAndExtendsTheOneEmailActivity(): void
    {
        $project = $this->project('Office move');
        $itemId = $this->receive(InboxMail::plain($this->address));
        $this->match($itemId);

        $this->send('POST', '/api/v1/inbox/items/'.$itemId.'/tasks', ['project_id' => $project->id(), 'title' => 'Answer the offer', 'description' => 'See the mail.'], $this->agent);
        self::assertResponseStatusCodeSame(201);
        $taskId = $this->at('data', 'id');
        self::assertIsString($taskId);
        self::assertSame(['Answer the offer', $project->id()], [$this->at('data', 'title'), $this->at('data', 'project', 'id')]);

        $this->get('/api/v1/inbox/items/'.$itemId, secret: $this->agent);
        self::assertSame('done', $this->at('data', 'state'));
        $outputs = (array) $this->at('data', 'outputs');
        self::assertCount(1, $outputs);
        $output = $outputs[0];
        self::assertIsArray($output);
        self::assertSame(['task', $taskId, true, 'Answer the offer', '/tasks/'.$taskId], [$output['kind'], $output['id'], $output['available'], $output['label'], $output['url']]);
        $activityId = $this->at('data', 'email_activity_id');
        self::assertIsString($activityId);
        self::assertEqualsCanonicalizing([['contact', $this->customerId], ['task', $taskId], ['project', $project->id()]], $this->linksOf($activityId));
    }

    public function testLogTimeFromEmailLinksTheProjectToTheEmailActivity(): void
    {
        $project = $this->project('Office move');
        $itemId = $this->receive(InboxMail::plain($this->address));

        $this->send('POST', '/api/v1/inbox/items/'.$itemId.'/time-entries', ['project_id' => $project->id(), 'worked_on' => '2026-11-24', 'minutes' => 45, 'note' => 'Offer call'], $this->agent);
        self::assertResponseStatusCodeSame(201);
        $entryId = $this->at('data', 'id');
        self::assertIsString($entryId);
        self::assertSame(45, $this->at('data', 'minutes'));

        $this->get('/api/v1/inbox/items/'.$itemId, secret: $this->agent);
        self::assertSame('done', $this->at('data', 'state'));
        $outputs = (array) $this->at('data', 'outputs');
        self::assertCount(1, $outputs);
        $output = $outputs[0];
        self::assertIsArray($output);
        self::assertSame(['time_entry', $entryId, false, null], [$output['kind'], $output['id'], $output['available'], $output['url']], 'No ActivityTarget offers a time entry: its kind shows, without a link.');
        $activityId = $this->at('data', 'email_activity_id');
        self::assertIsString($activityId);
        self::assertSame([['project', $project->id()]], $this->linksOf($activityId));
    }

    public function testTwoActionsOnOneItemGiveTwoOutputsAndOneActivity(): void
    {
        $project = $this->project('Office move');
        $itemId = $this->receive(InboxMail::plain($this->address));
        $this->match($itemId);

        $this->send('POST', '/api/v1/inbox/items/'.$itemId.'/tasks', ['project_id' => $project->id(), 'title' => 'Answer the offer', 'mark_done' => false], $this->agent);
        self::assertResponseStatusCodeSame(201);
        $taskId = $this->at('data', 'id');
        $this->get('/api/v1/inbox/items/'.$itemId, secret: $this->agent);
        $activityId = $this->at('data', 'email_activity_id');
        self::assertIsString($activityId);
        self::assertSame('new', $this->at('data', 'state'));

        $this->send('POST', '/api/v1/inbox/items/'.$itemId.'/time-entries', ['project_id' => $project->id(), 'task_id' => $taskId, 'worked_on' => '2026-11-24', 'minutes' => 30], $this->agent);
        self::assertResponseStatusCodeSame(201);
        $entryId = $this->at('data', 'id');

        $this->get('/api/v1/inbox/items/'.$itemId, secret: $this->agent);
        self::assertSame('done', $this->at('data', 'state'));
        self::assertSame([['task', $taskId], ['time_entry', $entryId]], array_map(static fn (mixed $output): array => \is_array($output) ? [$output['kind'], $output['id']] : [], (array) $this->at('data', 'outputs')));
        self::assertSame($activityId, $this->at('data', 'email_activity_id'), 'One e-mail activity, extended.');
        self::assertCount(3, $this->linksOf($activityId), 'The contact, the task and the project, each once.');
    }

    public function testLinkAfterTheFactAddsAnOutputForARecordMadeElsewhere(): void
    {
        $project = $this->project('Office move');
        $itemId = $this->receive(InboxMail::plain($this->address));

        $this->send('POST', '/api/v1/tasks', ['project_id' => $project->id(), 'title' => 'Made by hand'], $this->agent);
        self::assertResponseStatusCodeSame(201);
        $taskId = $this->at('data', 'id');
        self::assertIsString($taskId);

        $this->send('POST', '/api/v1/inbox/items/'.$itemId.'/outputs', ['kind' => 'task', 'id' => $taskId], $this->agent);
        self::assertResponseStatusCodeSame(201);
        $this->get('/api/v1/inbox/items/'.$itemId, secret: $this->agent);
        $output = ((array) $this->at('data', 'outputs'))[0] ?? null;
        self::assertIsArray($output);
        self::assertSame(['task', $taskId, true, 'Made by hand'], [$output['kind'], $output['id'], $output['available'], $output['label']]);
        $activityId = $this->at('data', 'email_activity_id');
        self::assertIsString($activityId);
        self::assertSame([['task', $taskId]], $this->linksOf($activityId));
    }

    public function testTheSingleActiveProjectIsPreselected(): void
    {
        $project = $this->project('Office move');
        $this->project('Other client project', false);
        $itemId = $this->receive(InboxMail::plain($this->address));
        $this->match($itemId);

        $actions = $this->actions($itemId);
        self::assertSame(['inbox.actions.create_task', 'inbox.actions.log_time'], array_keys($actions));
        $task = $actions['inbox.actions.create_task'];
        self::assertSame(['inbox_item_id' => $itemId, 'title' => 'Offer for the new office', 'description' => 'Hello, please find our offer below.', 'copy_attachments' => false, 'mark_done' => true, 'project_id' => $project->id()], $task['prefill']);
        self::assertSame(['project_id' => [$project->id() => 'Office move']], $task['choices'], 'The projects of the client.');
        $time = $actions['inbox.actions.log_time'];
        // The day it was received, in the instance's zone.
        self::assertSame(['inbox_item_id' => $itemId, 'worked_on' => self::getContainer()->get(InstanceClock::class)->today()->format('Y-m-d'), 'note' => 'Offer for the new office', 'mark_done' => true, 'project_id' => $project->id()], $time['prefill']);
        self::assertSame($task['choices'], $time['choices']);
        self::assertSame('Create task', $task['label']);
        self::assertSame('Log time', $time['label']);
    }

    public function testTwoActiveProjectsPreselectNone(): void
    {
        $first = $this->project('Office move');
        $second = $this->project('Roof audit');
        $itemId = $this->receive(InboxMail::plain($this->address));
        $this->match($itemId);

        $task = $this->actions($itemId)['inbox.actions.create_task'];
        self::assertArrayNotHasKey('project_id', $task['prefill']);
        self::assertSame(['project_id' => [$first->id() => 'Office move', $second->id() => 'Roof audit']], $task['choices']);
    }

    public function testAnArchivedProjectIsIgnored(): void
    {
        $active = $this->project('Office move');
        $old = $this->project('Old roof');
        $this->as(fn () => $this->service(ManageProjects::class)->archive($old->id()));
        $itemId = $this->receive(InboxMail::plain($this->address));
        $this->match($itemId);

        $task = $this->actions($itemId)['inbox.actions.create_task'];
        self::assertSame($active->id(), $task['prefill']['project_id']);
        self::assertSame(['project_id' => [$active->id() => 'Office move']], $task['choices']);
    }

    public function testAnItemOfNoClientOffersAllTheActiveProjectsAndPreselectsNone(): void
    {
        $project = $this->project('Office move', false);
        $itemId = $this->receive(InboxMail::plain($this->address, from: 'Someone <someone@unknown.example.com>'));

        $task = $this->actions($itemId)['inbox.actions.create_task'];
        self::assertArrayNotHasKey('project_id', $task['prefill']);
        self::assertSame(['project_id' => [$project->id() => 'Office move']], $task['choices']);
    }

    public function testCopyAttachmentsCopiesTheFilesOfTheMailOntoTheTask(): void
    {
        $project = $this->project('Office move');
        $itemId = $this->receive($this->withAttachment());

        self::assertTrue($this->actions($itemId)['inbox.actions.create_task']['prefill']['copy_attachments']);
        $this->send('POST', '/api/v1/inbox/items/'.$itemId.'/tasks', ['project_id' => $project->id(), 'title' => 'Read the offer'], $this->agent);
        self::assertResponseStatusCodeSame(201);
        $taskId = $this->at('data', 'id');
        self::assertIsString($taskId);

        self::assertSame(['offer.pdf'], $this->attachmentNames($taskId));
    }

    public function testCopyAttachmentsCanBeUnticked(): void
    {
        $project = $this->project('Office move');
        $itemId = $this->receive($this->withAttachment());

        $this->send('POST', '/api/v1/inbox/items/'.$itemId.'/tasks', ['project_id' => $project->id(), 'title' => 'Read the offer', 'copy_attachments' => false], $this->agent);
        self::assertResponseStatusCodeSame(201);
        $taskId = $this->at('data', 'id');
        self::assertIsString($taskId);

        self::assertSame([], $this->attachmentNames($taskId));
    }

    public function testAPersonWhoCannotSeeTheProjectIsRefusedByWorkAndTheItemStaysNew(): void
    {
        $secret = $this->chosenProject('Secret');
        $itemId = $this->receive(InboxMail::plain($this->address));

        $this->send('POST', '/api/v1/inbox/items/'.$itemId.'/tasks', ['project_id' => $secret->id(), 'title' => 'Answer the offer'], $this->agent);
        self::assertResponseStatusCodeSame(404);
        $this->send('POST', '/api/v1/inbox/items/'.$itemId.'/time-entries', ['project_id' => $secret->id(), 'worked_on' => '2026-11-24', 'minutes' => 10], $this->agent);
        self::assertResponseStatusCodeSame(404);

        $this->get('/api/v1/inbox/items/'.$itemId, secret: $this->agent);
        self::assertSame(['new', [], null], [$this->at('data', 'state'), $this->at('data', 'outputs'), $this->at('data', 'email_activity_id')]);
        self::assertEquals(0, $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM task'), 'No task was made.');
    }

    public function testTheTaskActionFillsTheDescriptionWithAtMostTheLimit(): void
    {
        $itemId = $this->receive(InboxMail::plain($this->address, body: str_repeat('x', CreateTaskFromEmailPreFill::MAX_DESCRIPTION + 50)."\r\n"));

        $description = $this->actions($itemId)['inbox.actions.create_task']['prefill']['description'] ?? null;
        self::assertIsString($description);
        self::assertSame(CreateTaskFromEmailPreFill::MAX_DESCRIPTION, mb_strlen($description));
    }

    /**
     * The project of the client of the mail unless told otherwise.
     */
    protected function project(string $name = 'Website', bool $ofTheClient = true): Project
    {
        return $this->as(fn (): Project => $this->service(ManageProjects::class)->create($name, $ofTheClient ? $this->customerId : null));
    }

    /**
     * @return array<string, array{label: string, prefill: array<mixed>, choices: array<mixed>}> by operation
     */
    private function actions(string $itemId): array
    {
        $this->get('/api/v1/inbox/items/'.$itemId, secret: $this->agent);
        self::assertResponseIsSuccessful();
        $actions = [];
        foreach ((array) $this->at('data', 'available_actions') as $action) {
            self::assertIsArray($action);
            self::assertIsString($action['operation']);
            self::assertIsString($action['label']);
            self::assertIsArray($action['prefill']);
            self::assertIsArray($action['choices'] ?? []);
            $actions[$action['operation']] = ['label' => $action['label'], 'prefill' => $action['prefill'], 'choices' => $action['choices'] ?? []];
        }

        return $actions;
    }

    /**
     * @return list<array{string, string}> the kind and id of what the activity is linked to
     */
    private function linksOf(string $activityId): array
    {
        $links = [];
        foreach ($this->em()->getConnection()->fetchAllAssociative('SELECT kind, target_id FROM activities_activity_link WHERE activity_id = ? ORDER BY kind', [$activityId]) as $link) {
            self::assertIsString($link['kind']);
            self::assertIsString($link['target_id']);
            $links[] = [$link['kind'], $link['target_id']];
        }

        return $links;
    }

    /**
     * @return list<string>
     */
    private function attachmentNames(string $taskId): array
    {
        $tokens = self::getContainer()->get(TokenStorageInterface::class);
        $tokens->setToken(new UsernamePasswordToken(SecurityUser::fromUser($this->user), 'main', ['ROLE_USER']));
        try {
            return array_map(static fn (\Foresi\Attachment\Domain\Attachment $file): string => $file->fileName(), $this->service(VisibleAttachments::class)->of(TaskAttachmentAccess::KIND, $taskId));
        } finally {
            $tokens->setToken(null);
        }
    }

    private function match(string $itemId): void
    {
        $this->send('PUT', '/api/v1/inbox/items/'.$itemId.'/contact', ['contact_id' => $this->customerId], $this->agent);
        self::assertResponseIsSuccessful();
    }

    private function receive(string $raw): string
    {
        self::assertSame(ReceiveOutcome::Stored, self::getContainer()->get(ReceiveMail::class)->receive($raw, InboundChannel::Webhook, $this->address));
        $messageId = $this->em()->getConnection()->fetchOne('SELECT id FROM mail_message WHERE raw_sha256 = ?', [hash('sha256', $raw)]);
        self::assertIsString($messageId);
        $item = new DoctrineItemStore($this->em())->itemOfMessage($messageId);
        self::assertInstanceOf(InboxItem::class, $item);

        return $item->id();
    }

    private function withAttachment(): string
    {
        return implode("\r\n", [
            'Authentication-Results: '.InboxMail::verifiedBy('client-a.example.com'),
            'From: Client A <client.a@client-a.example.com>',
            'To: '.$this->address,
            'Subject: Offer with a file',
            'Date: Tue, 24 Nov 2026 09:35:00 +0100',
            'Message-ID: <offer-2@client-a.example.com>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="b1"',
            '',
            '--b1',
            'Content-Type: text/plain; charset=utf-8',
            'Content-Transfer-Encoding: 8bit',
            '',
            'Hello, the offer is attached.',
            '--b1',
            'Content-Type: application/pdf; name="offer.pdf"',
            'Content-Disposition: attachment; filename="offer.pdf"',
            'Content-Transfer-Encoding: base64',
            '',
            base64_encode('%PDF-1.4 example'),
            '--b1--',
            '',
        ]);
    }
}
