<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Integration\Inbox;

use Doctrine\DBAL\Driver\PDO\Exception as DriverException;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\ORM\EntityManagerInterface;
use Foresi\Activities\Domain\ActivityTiming;
use Foresi\Activities\Domain\ActivityType;
use Foresi\Attachment\Application\AttachmentFiles;
use Foresi\Attachment\Application\AttachmentRefused;
use Foresi\Attachment\Application\Attachments;
use Foresi\Attachment\Application\VisibleAttachments;
use Foresi\Attachment\Domain\Attachment;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Foresi\Inbox\Application\InboxActions;
use Foresi\Inbox\Application\InboxPurpose;
use Foresi\Inbox\Application\RecordOutputs;
use Foresi\Inbox\Contract\InboxAction;
use Foresi\Inbox\Domain\InboxItem;
use Foresi\Inbox\Infrastructure\Doctrine\DoctrineItemStore;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Mail\Application\ReceiveMail;
use Foresi\Mail\Application\ReceiveOutcome;
use Foresi\Mail\Contract\AddressIssuer;
use Foresi\Mail\Domain\InboundChannel;
use Foresi\Tests\Inbox\Support\ContainerPreFills;
use Foresi\Tests\Inbox\Support\InboxMail;
use Foresi\Tests\Inbox\Support\ItemViews;
use Foresi\Tests\Inbox\Support\McpToolCalls;
use Foresi\Tests\Inbox\Support\RebuiltServices;
use Foresi\Tests\Shared\Infrastructure\Mcp\RecordingLogger;
use Foresi\Tests\Work\Infrastructure\Api\WorkApiTestCase;
use Foresi\Work\Application\ManageProjects;
use Foresi\Work\Application\ManageTasks;
use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\ProjectBudgetChange;
use Foresi\Work\Domain\Task;
use Foresi\Work\Domain\TimeEntry;
use Foresi\Work\Infrastructure\Attachment\TaskAttachmentAccess;
use Foresi\Work\Integration\Activities\TaskActivityTarget;
use Foresi\Work\Integration\Inbox\CreateTaskFromEmailHandler;
use Foresi\Work\Integration\Inbox\CreateTaskFromEmailInput;
use Foresi\Work\Integration\Inbox\CreateTaskFromEmailPreFill;
use Foresi\Work\Integration\Inbox\LogTimeFromEmailInput;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The inbox actions of Work: a task or time made from an inbox item with the
 * project of its client pre-selected, recorded as outputs of the item, the one
 * e-mail activity of the item linked to what they made and to the project.
 */
final class WorkInboxActionsTest extends WorkApiTestCase
{
    use ContainerPreFills;
    use ItemViews;
    use McpToolCalls;
    use RebuiltServices;

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
        $this->agent = $this->issued([ApiScope::from('inbox:write'), ApiScope::from('tasks:write'), ApiScope::from('time_entries:write'), ApiScope::from('projects:write')]);
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

    public function testWhileTheEmailTypeIsArchivedLogTimeIsRefusedBeforeAnyEntryIsMadeSoARetryMakesNoSecond(): void
    {
        $project = $this->project('Office move');
        $itemId = $this->receive(InboxMail::plain($this->address));
        $this->setEmailTypeArchived(true);
        $body = ['project_id' => $project->id(), 'worked_on' => '2026-11-24', 'minutes' => 45];

        $this->send('POST', '/api/v1/inbox/items/'.$itemId.'/time-entries', $body, $this->agent);
        $this->assertProblem(409, 'email_activity_unavailable');
        $call = $this->callTool($this->agent, 'foresi_log_time_from_email', ['inbox_item_id' => $itemId] + $body);
        self::assertTrue($call['isError'] ?? null, 'The retry an agent makes is refused too.');
        self::assertSame('email_activity_unavailable', ((array) ((array) ($call['structuredContent'] ?? []))['error'])['code'] ?? null);

        self::assertEquals(0, $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM time_entry'), 'No time entry was made, so none is billed twice.');
        $this->get('/api/v1/inbox/items/'.$itemId, secret: $this->agent);
        self::assertSame(['new', [], null], [$this->at('data', 'state'), $this->at('data', 'outputs'), $this->at('data', 'email_activity_id')]);

        $this->setEmailTypeArchived(false);
        $this->send('POST', '/api/v1/inbox/items/'.$itemId.'/time-entries', $body, $this->agent);
        self::assertResponseStatusCodeSame(201);
        self::assertEquals(1, $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM time_entry'));
    }

    public function testAnItemWithItsEmailActivityTakesActionsWhileTheTypeIsArchived(): void
    {
        $project = $this->project('Office move');
        $itemId = $this->receive(InboxMail::plain($this->address));
        $this->send('POST', '/api/v1/inbox/items/'.$itemId.'/tasks', ['project_id' => $project->id(), 'title' => 'Answer the offer', 'mark_done' => false], $this->agent);
        self::assertResponseStatusCodeSame(201);
        $this->setEmailTypeArchived(true);

        $this->send('POST', '/api/v1/inbox/items/'.$itemId.'/time-entries', ['project_id' => $project->id(), 'worked_on' => '2026-11-24', 'minutes' => 30], $this->agent);

        self::assertResponseStatusCodeSame(201);
        $this->get('/api/v1/inbox/items/'.$itemId, secret: $this->agent);
        self::assertSame(['task', 'time_entry'], array_map(static fn (mixed $output): mixed => \is_array($output) ? $output['kind'] : null, (array) $this->at('data', 'outputs')), 'The existing e-mail activity is extended.');
    }

    public function testOnlyATaskCanBeLinkedByHandWhenItsRecordingFails(): void
    {
        $outputs = self::getContainer()->get(RecordOutputs::class);
        $task = InboxActions::attributeOf(CreateTaskFromEmailInput::class);
        $time = InboxActions::attributeOf(LogTimeFromEmailInput::class);
        self::assertInstanceOf(InboxAction::class, $task);
        self::assertInstanceOf(InboxAction::class, $time);

        self::assertSame([true, false], [$outputs->linksByHand($task), $outputs->linksByHand($time)], 'inbox.items.link takes a task, never a time entry.');
    }

    public function testBothActionsRunOverMcp(): void
    {
        $project = $this->project('Office move');
        $itemId = $this->receive(InboxMail::plain($this->address));

        $task = $this->callTool($this->agent, 'foresi_create_task_from_email', ['inbox_item_id' => $itemId, 'project_id' => $project->id(), 'title' => 'Answer the offer', 'mark_done' => false]);
        self::assertFalse($task['isError'] ?? null);
        $taskId = ((array) ((array) ($task['structuredContent'] ?? []))['data'])['id'] ?? null;
        self::assertIsString($taskId);
        $time = $this->callTool($this->agent, 'foresi_log_time_from_email', ['inbox_item_id' => $itemId, 'project_id' => $project->id(), 'task_id' => $taskId, 'worked_on' => '2026-11-24', 'minutes' => 30]);
        self::assertFalse($time['isError'] ?? null);
        $entryId = ((array) ((array) ($time['structuredContent'] ?? []))['data'])['id'] ?? null;
        self::assertIsString($entryId);

        $this->get('/api/v1/inbox/items/'.$itemId, secret: $this->agent);
        self::assertSame('done', $this->at('data', 'state'));
        self::assertSame([['task', $taskId], ['time_entry', $entryId]], array_map(static fn (mixed $output): array => \is_array($output) ? [$output['kind'], $output['id']] : [], (array) $this->at('data', 'outputs')));
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
        self::assertSame(['inbox.actions.create_task', 'inbox.actions.log_time', 'inbox.actions.add_to_budget'], array_keys($actions));
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

    public function testAddToBudgetFromEmailLinksTheActivityAndTheChange(): void
    {
        $project = $this->project('DUD development');
        $itemId = $this->receive(InboxMail::plain($this->address));
        $this->match($itemId);

        $this->send('POST', '/api/v1/inbox/items/'.$itemId.'/budget-changes', ['project_id' => $project->id(), 'hours' => '8', 'effective_on' => '2026-11-24', 'note' => '8 h approved'], $this->agent);

        self::assertResponseStatusCodeSame(201);
        $changeId = $this->at('data', 'id');
        self::assertIsString($changeId);
        self::assertSame([$project->id(), 480, 8], [$this->at('data', 'project_id'), $this->at('data', 'minutes'), $this->at('data', 'hours')]);
        $change = ((array) $this->at('data', 'changes'))[0] ?? null;
        self::assertIsArray($change);
        $this->get('/api/v1/inbox/items/'.$itemId, secret: $this->agent);
        $activityId = $this->at('data', 'email_activity_id');
        self::assertIsString($activityId);
        self::assertSame([$changeId, '2026-11-24', 480, '8 h approved', $activityId], [$change['id'], $change['effective_on'], $change['minutes'], $change['note'], $change['source_activity_id']], 'The e-mail activity of the item is the source.');
        self::assertEqualsCanonicalizing([['contact', $this->customerId], ['project', $project->id()]], $this->linksOf($activityId));
        self::assertSame('done', $this->at('data', 'state'));
        $outputs = (array) $this->at('data', 'outputs');
        self::assertCount(1, $outputs);
        $output = $outputs[0];
        self::assertIsArray($output);
        self::assertSame(['project_budget_change', $changeId], [$output['kind'], $output['id']]);

        $this->send('POST', '/api/v1/inbox/items/'.$itemId.'/budget-changes', ['project_id' => $project->id(), 'hours' => '2', 'mark_done' => false], $this->agent);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(600, $this->at('data', 'minutes'));
        $this->get('/api/v1/inbox/items/'.$itemId, secret: $this->agent);
        self::assertSame($activityId, $this->at('data', 'email_activity_id'), 'One e-mail activity, used again.');
    }

    public function testAddToBudgetFromEmailRefusedByTheBudgetLeavesNoChangeAndTheItemNew(): void
    {
        $project = $this->project('DUD development');
        $itemId = $this->receive(InboxMail::plain($this->address));
        $this->match($itemId);

        $this->send('POST', '/api/v1/inbox/items/'.$itemId.'/budget-changes', ['project_id' => $project->id(), 'hours' => '-3'], $this->agent);

        $this->assertProblem(409, 'budget_below_zero');
        self::assertEquals(0, $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM project_budget_change'));
        $this->get('/api/v1/inbox/items/'.$itemId, secret: $this->agent);
        self::assertSame(['new', []], [$this->at('data', 'state'), $this->at('data', 'outputs')], 'Nothing came of the item; the e-mail activity it made stays, linked to the project.');
    }

    public function testAddToBudgetIsNotAvailableWhenTheClientHasNoActiveProject(): void
    {
        $project = $this->project('Other client project', false);
        $itemId = $this->receive(InboxMail::plain($this->address));
        $this->match($itemId);

        self::assertSame(['inbox.actions.create_task', 'inbox.actions.log_time'], array_keys($this->actions($itemId)));
        $this->send('POST', '/api/v1/inbox/items/'.$itemId.'/budget-changes', ['project_id' => $project->id(), 'hours' => '8'], $this->agent);
        $this->assertProblem(409, 'action_not_applicable');
        self::assertEquals(0, $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM project_budget_change'));
    }

    public function testTheAddToBudgetPreFillIsTheSingleProjectAndTodayWithTheSubjectAsTheNote(): void
    {
        $project = $this->project('DUD development');
        $itemId = $this->receive(InboxMail::plain($this->address));
        $this->match($itemId);

        $budget = $this->actions($itemId)['inbox.actions.add_to_budget'];

        self::assertSame('Add to project budget', $budget['label']);
        self::assertSame(['inbox_item_id' => $itemId, 'effective_on' => self::getContainer()->get(InstanceClock::class)->today()->format('Y-m-d'), 'note' => 'Offer for the new office', 'mark_done' => true, 'project_id' => $project->id()], $budget['prefill']);
        self::assertSame(['project_id' => [$project->id() => 'DUD development']], $budget['choices']);
        $item = $this->itemView(subject: '  '.str_repeat('ž', ProjectBudgetChange::MAX_NOTE_LENGTH + 20).'  ');
        $values = $this->as(fn (): array => $this->prefillOf('inbox.actions.add_to_budget', $item)->values, $this->user);
        self::assertSame(str_repeat('ž', ProjectBudgetChange::MAX_NOTE_LENGTH), $values['note']);
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

    public function testTheTaskPreFillTakesTheSubjectAndTheTextTrimmedAndCut(): void
    {
        $item = $this->itemView(subject: '  '.str_repeat('ž', Task::MAX_TITLE_LENGTH + 20).'  ', text: "\n  ".str_repeat('é', CreateTaskFromEmailPreFill::MAX_DESCRIPTION + 10)."  \n");

        $values = $this->as(fn (): array => $this->prefillOf('inbox.actions.create_task', $item)->values, $this->user);

        self::assertSame(str_repeat('ž', Task::MAX_TITLE_LENGTH), $values['title']);
        self::assertSame(str_repeat('é', CreateTaskFromEmailPreFill::MAX_DESCRIPTION), $values['description']);
        self::assertFalse($values['copyAttachments']);
        self::assertTrue($values['markDone']);
    }

    public function testTheTimePreFillTakesTheSubjectTrimmedAndCut(): void
    {
        $item = $this->itemView(subject: '  '.str_repeat('ž', TimeEntry::MAX_NOTE_LENGTH + 20).'  ');

        $values = $this->as(fn (): array => $this->prefillOf('inbox.actions.log_time', $item)->values, $this->user);

        self::assertSame(str_repeat('ž', TimeEntry::MAX_NOTE_LENGTH), $values['note']);
        self::assertSame('2026-11-24', $values['workedOn']);
        self::assertTrue($values['markDone']);
    }

    public function testTheTaskIsAnsweredWithItsAddress(): void
    {
        $project = $this->project('Office move');
        $itemId = $this->receive(InboxMail::plain($this->address));

        $this->send('POST', '/api/v1/inbox/items/'.$itemId.'/tasks', ['project_id' => $project->id(), 'title' => 'Answer the offer'], $this->agent);

        self::assertResponseStatusCodeSame(201);
        $taskId = $this->at('data', 'id');
        self::assertIsString($taskId);
        self::assertSame('/api/v1/tasks/'.$taskId, $this->client->getResponse()->headers->get('Location'));
    }

    public function testTheFilesCopiedAreNotMentionedAndNoTemporaryFileStays(): void
    {
        $project = $this->project('Office move');
        $bytes = '%PDF-1.4 '.bin2hex(random_bytes(8));
        $itemId = $this->receive($this->withAttachment($bytes));

        $answer = $this->createTask($itemId, $project, copyAttachments: true);

        self::assertSame('Created the task Read the offer from the e-mail.', $answer->summary);
        self::assertNotNull($answer->location);
        self::assertSame(['offer.pdf'], $this->attachmentNames($this->taskIdOf($answer)));
        self::assertSame([], $this->leftOver($bytes));
    }

    public function testNothingIsSaidOfFilesThatWereNotAskedFor(): void
    {
        $project = $this->project('Office move');
        $itemId = $this->receive($this->withAttachment('%PDF-1.4 not asked'));

        $answer = $this->createTask($itemId, $project, copyAttachments: false);

        self::assertSame('Created the task Read the offer from the e-mail.', $answer->summary);
        self::assertSame([], $this->attachmentNames($this->taskIdOf($answer)));
    }

    public function testAFileThatCannotBeKeptIsCountedLoggedByIdsAndLeavesNoTemporaryFile(): void
    {
        $project = $this->project('Office move');
        $bytes = '%PDF-1.4 '.bin2hex(random_bytes(8));
        $itemId = $this->receive($this->withAttachment($bytes));
        $this->storageThrows(new \LogicException('The storage broke offer.pdf.'));
        $logger = new RecordingLogger();

        $answer = $this->createTask($itemId, $project, copyAttachments: true, logger: $logger);

        self::assertSame('Created the task Read the offer from the e-mail. 1 of the files of the mail could not be copied onto it.', $answer->summary);
        self::assertSame([], $this->attachmentNames($this->taskIdOf($answer)));
        self::assertSame([['error', 'A file of an inbox item could not be copied onto its task.', ['item_id' => $itemId, 'task_id' => $this->taskIdOf($answer), 'failure' => \LogicException::class]]], $logger->records, 'Ids and the class: not the name of the file, nor the message.');
        self::assertSame([], $this->leftOver($bytes));
    }

    public function testAFileTheStorageRefusesIsCountedButNotLogged(): void
    {
        $project = $this->project('Office move');
        $itemId = $this->receive($this->withAttachment('%PDF-1.4 refused'));
        $this->storageThrows(new AttachmentRefused(AttachmentRefused::FAILED));
        $logger = new RecordingLogger();

        $answer = $this->createTask($itemId, $project, copyAttachments: true, logger: $logger);

        self::assertSame('Created the task Read the offer from the e-mail. 1 of the files of the mail could not be copied onto it.', $answer->summary);
        self::assertSame([], $logger->records, 'A refusal is the ordinary way a file is not kept.');
    }

    public function testTheTemporaryFileIsRemovedEvenWhenTheLoggerFails(): void
    {
        $project = $this->project('Office move');
        $bytes = '%PDF-1.4 '.bin2hex(random_bytes(8));
        $itemId = $this->receive($this->withAttachment($bytes));
        $this->storageThrows(new \LogicException('The storage broke.'));
        $logger = new class extends \Psr\Log\AbstractLogger {
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                throw new \RuntimeException('The log is gone.');
            }
        };

        try {
            $this->createTask($itemId, $project, copyAttachments: true, logger: $logger);
            self::fail('The failure of the logger was swallowed.');
        } catch (\RuntimeException $failure) {
            self::assertSame('The log is gone.', $failure->getMessage());
        }

        self::assertSame([], $this->leftOver($bytes));
    }

    public function testAFileThatCannotBeCopiedForAnyReasonLeavesTheTaskAsAnOutput(): void
    {
        $project = $this->project('Office move');
        $itemId = $this->receive($this->withAttachment());
        // A storage failure that is not an AttachmentRefused nor a RuntimeException.
        self::getContainer()->set(AttachmentFiles::class, new class implements AttachmentFiles {
            public function store(string $attachmentId, string $sourcePath): string
            {
                throw new \LogicException('The storage broke.');
            }

            public function read(string $attachmentId, string $key, string $sha256): string
            {
                throw new \LogicException('The storage broke.');
            }

            public function locate(string $attachmentId, string $key): string
            {
                throw new \LogicException('The storage broke.');
            }

            public function remove(string $key): void
            {
            }
        });

        $this->send('POST', '/api/v1/inbox/items/'.$itemId.'/tasks', ['project_id' => $project->id(), 'title' => 'Read the offer'], $this->agent);
        self::assertResponseStatusCodeSame(201);
        $taskId = $this->at('data', 'id');
        self::assertIsString($taskId);

        $this->get('/api/v1/inbox/items/'.$itemId, secret: $this->agent);
        self::assertSame('done', $this->at('data', 'state'));
        self::assertSame([['task', $taskId]], array_map(static fn (mixed $output): array => \is_array($output) ? [$output['kind'], $output['id']] : [], (array) $this->at('data', 'outputs')));
        self::assertSame([], $this->attachmentNames($taskId));
    }

    public function testADatabaseErrorWhileKeepingAFileLeavesTheTaskAsAnOutput(): void
    {
        $project = $this->project('Office move');
        $itemId = $this->receive($this->withAttachment());
        $em = $this->em();
        // The flush of the attachment fails in the database and closes the entity manager, as a constraint violation does.
        self::getContainer()->set(Attachments::class, new readonly class($em) implements Attachments {
            public function __construct(private EntityManagerInterface $em)
            {
            }

            public function of(string $ownerKind, string $ownerId): array
            {
                return [];
            }

            public function get(string $id): ?Attachment
            {
                return null;
            }

            public function withDeleted(string $id): ?Attachment
            {
                return null;
            }

            public function deletedOf(string $ownerKind, string $ownerId): array
            {
                return [];
            }

            public function deletedBefore(\DateTimeImmutable $moment): array
            {
                return [];
            }

            public function allOf(string $ownerKind, array $ownerIds): array
            {
                return [];
            }

            public function remove(Attachment $attachment): void
            {
            }

            public function add(Attachment $attachment): void
            {
            }

            public function flush(): void
            {
                $this->em->close();

                throw new ConnectionLost(DriverException::new(new \PDOException('The database went away.')), null);
            }
        });

        $this->send('POST', '/api/v1/inbox/items/'.$itemId.'/tasks', ['project_id' => $project->id(), 'title' => 'Read the offer'], $this->agent);
        self::assertResponseStatusCodeSame(201);
        $taskId = $this->at('data', 'id');
        self::assertIsString($taskId);

        $this->get('/api/v1/inbox/items/'.$itemId, secret: $this->agent);
        self::assertSame('done', $this->at('data', 'state'));
        self::assertSame([['task', $taskId]], array_map(static fn (mixed $output): array => \is_array($output) ? [$output['kind'], $output['id']] : [], (array) $this->at('data', 'outputs')));
    }

    public function testAProjectThePersonCannotSeeIsNeverProposed(): void
    {
        $visible = $this->project('Office move');
        $this->chosenProject('Secret');
        $itemId = $this->receive(InboxMail::plain($this->address));
        $this->match($itemId);

        $task = $this->actions($itemId)['inbox.actions.create_task'];
        self::assertSame($visible->id(), $task['prefill']['project_id'] ?? null, 'The one project the person sees.');
        self::assertSame(['project_id' => [$visible->id() => 'Office move']], $task['choices']);
    }

    public function testOnlyAProjectThePersonCannotSeeProposesNothingAndNamesNothing(): void
    {
        $secret = $this->chosenProject('Secret');
        $itemId = $this->receive(InboxMail::plain($this->address));
        $this->match($itemId);

        $task = $this->actions($itemId)['inbox.actions.create_task'];
        self::assertArrayNotHasKey('project_id', $task['prefill']);
        self::assertSame(['project_id' => []], $task['choices']);
        self::assertStringNotContainsString($secret->id(), (string) json_encode($task));
    }

    public function testTheTaskTargetFollowsWhatThePersonSees(): void
    {
        $project = $this->project('Office move');
        $open = $this->task($project, 'Open task');
        $private = $this->privateTask($project, 'Private task');
        $trashed = $this->task($project, 'Trashed task');
        $this->as(fn () => $this->service(ManageTasks::class)->delete($trashed));
        $archivedProject = $this->project('Old roof');
        $inArchived = $this->task($archivedProject, 'Task of an archived project');
        $this->as(fn () => $this->service(ManageProjects::class)->archive($archivedProject->id()));

        $target = $this->service(TaskActivityTarget::class);
        $seen = $this->as(static fn (): array => [
            [$target->canSee('task', $open), $target->canLink('task', $open), $target->label('task', $open), $target->url('task', $open)],
            [$target->canSee('task', $private), $target->canLink('task', $private), $target->label('task', $private), $target->url('task', $private)],
            [$target->canSee('task', $trashed), $target->canLink('task', $trashed), $target->label('task', $trashed), $target->url('task', $trashed)],
            [$target->canSee('task', $inArchived), $target->canLink('task', $inArchived), $target->label('task', $inArchived), $target->url('task', $inArchived)],
            [$target->canSee('project', $open), $target->canSee('task', 'not-an-id')],
        ], $this->user);

        self::assertSame([true, true, 'Open task', '/tasks/'.$open], $seen[0]);
        self::assertSame([false, false, null, null], $seen[1], 'A private task is not the member\'s to see.');
        self::assertSame([false, false, null, null], $seen[2], 'A task in the trash is gone.');
        self::assertSame([true, false, 'Task of an archived project', '/tasks/'.$inArchived], $seen[3], 'Seen, but an archived project takes no new activity.');
        self::assertSame([false, false], $seen[4]);
        $asOwner = $this->as(static fn (): array => [$target->canSee('task', $private), $target->label('task', $private)]);
        self::assertSame([true, 'Private task'], $asOwner, 'The owner sees every task.');
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
            return array_map(static fn (Attachment $file): string => $file->fileName(), $this->service(VisibleAttachments::class)->of(TaskAttachmentAccess::KIND, $taskId));
        } finally {
            $tokens->setToken(null);
        }
    }

    private function setEmailTypeArchived(bool $archived): void
    {
        $this->em()->getConnection()->executeStatement('UPDATE activities_type SET archived_at = ? WHERE type_key = ?', [$archived ? '2026-11-24 10:00:00' : null, 'email']);
        $this->em()->clear();
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

    private function createTask(string $itemId, Project $project, bool $copyAttachments, ?\Psr\Log\LoggerInterface $logger = null): \Foresi\Shared\Contract\Operation\OperationOutput
    {
        $handler = $this->rebuilt(self::getContainer()->get(CreateTaskFromEmailHandler::class), $logger instanceof \Psr\Log\LoggerInterface ? ['logger' => $logger] : []);

        return $this->as(static fn () => $handler->handle(new CreateTaskFromEmailInput($itemId, $project->id(), 'Read the offer', copyAttachments: $copyAttachments)), $this->user);
    }

    private function taskIdOf(\Foresi\Shared\Contract\Operation\OperationOutput $answer): string
    {
        $id = $answer->data['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    private function storageThrows(\Throwable $failure): void
    {
        self::getContainer()->set(AttachmentFiles::class, new readonly class($failure) implements AttachmentFiles {
            public function __construct(private \Throwable $failure)
            {
            }

            public function store(string $attachmentId, string $sourcePath): string
            {
                throw $this->failure;
            }

            public function read(string $attachmentId, string $key, string $sha256): string
            {
                throw $this->failure;
            }

            public function locate(string $attachmentId, string $key): string
            {
                throw $this->failure;
            }

            public function remove(string $key): void
            {
            }
        });
    }

    /**
     * @return list<string> the temporary files of the copy that hold these bytes
     */
    private function leftOver(string $bytes): array
    {
        $files = glob(sys_get_temp_dir().'/foresi-inbox-*');

        return array_values(array_filter(false === $files ? [] : $files, static fn (string $file): bool => is_file($file) && $bytes === file_get_contents($file)));
    }

    private function withAttachment(string $bytes = '%PDF-1.4 example'): string
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
            base64_encode($bytes),
            '--b1--',
            '',
        ]);
    }
}
