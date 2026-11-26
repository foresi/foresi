<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Timesheet\Integration\Inbox;

use Foresi\Inbox\Application\InboxActions;
use Foresi\Inbox\Application\RecordOutputs;
use Foresi\Inbox\Contract\InboxAction;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Tests\Inbox\Support\ContainerPreFills;
use Foresi\Tests\Inbox\Support\InboxMail;
use Foresi\Tests\Inbox\Support\ItemViews;
use Foresi\Tests\Inbox\Support\McpToolCalls;
use Foresi\Tests\Inbox\Support\RebuiltServices;
use Foresi\Tests\Work\Integration\Inbox\WorkInboxTestCase;
use Foresi\Timesheet\Domain\ProjectBudgetChange;
use Foresi\Timesheet\Domain\TimeEntry;
use Foresi\Timesheet\Integration\Inbox\LogTimeFromEmailInput;
use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\Task;
use Foresi\Work\Integration\Inbox\CreateTaskFromEmailInput;

/**
 * Timesheet's actions of an inbox item: time logged from an e-mail and a change
 * of a project's time budget from it, alone and beside Work's new task.
 */
final class TimesheetInboxActionsTest extends WorkInboxTestCase
{
    // The helpers of the traits are private: each test class takes them for itself.
    use ContainerPreFills;
    use ItemViews;
    use McpToolCalls;
    use RebuiltServices;

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

    public function testTheTimePreFillTakesTheSubjectTrimmedAndCut(): void
    {
        $item = $this->itemView(subject: '  '.str_repeat('ž', TimeEntry::MAX_NOTE_LENGTH + 20).'  ');

        $values = $this->as(fn (): array => $this->prefillOf('inbox.actions.log_time', $item)->values, $this->user);

        self::assertSame(str_repeat('ž', TimeEntry::MAX_NOTE_LENGTH), $values['note']);
        self::assertSame('2026-11-24', $values['workedOn']);
        self::assertTrue($values['markDone']);
    }
}
