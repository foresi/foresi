<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Integration\Work\Application;

use Foresi\Activities\Domain\Activity;
use Foresi\Activities\Domain\ActivityTiming;
use Foresi\Activities\Domain\ActivityType;
use Foresi\Audit\Domain\AuditEntry;
use Foresi\Identity\Domain\Role;
use Foresi\Inbox\Application\Api\DiscardItemInput;
use Foresi\Inbox\Application\Api\MarkItemDoneInput;
use Foresi\Inbox\Application\Api\TrashItemInput;
use Foresi\Inbox\Application\DecideSuggestions;
use Foresi\Inbox\Application\Decision;
use Foresi\Inbox\Application\InboxActions;
use Foresi\Inbox\Application\InboxRefused;
use Foresi\Inbox\Application\ItemWrites;
use Foresi\Inbox\Application\ProposeSuggestions;
use Foresi\Inbox\Application\ProposingClient;
use Foresi\Inbox\Application\RecordOutputs;
use Foresi\Inbox\Application\StepRunner;
use Foresi\Inbox\Application\SuggestionStore;
use Foresi\Inbox\Domain\ItemState;
use Foresi\Inbox\Domain\StepState;
use Foresi\Inbox\Domain\Suggestion;
use Foresi\Inbox\Domain\SuggestionState;
use Foresi\Inbox\Domain\SuggestionStep;
use Foresi\Inbox\Infrastructure\Doctrine\DoctrineItemStore;
use Foresi\Inbox\Infrastructure\Doctrine\DoctrineSuggestionStore;
use Foresi\Shared\Contract\Operation\OperationOutput;
use Foresi\Shared\Contract\Refused;
use Foresi\Shared\Domain\Id;
use Foresi\Tests\Inbox\Fixtures\Action\FixtureNoteInput;
use Foresi\Tests\Inbox\Fixtures\Action\FixtureNotes;
use Foresi\Tests\Inbox\Support\InboxMail;
use Foresi\Tests\Inbox\Support\InboxTestCase;
use Foresi\Tests\Inbox\Support\LockLog;
use Foresi\Tests\Inbox\Support\LockRecordingItems;
use Foresi\Tests\Inbox\Support\LockRecordingSuggestions;
use Foresi\Tests\Inbox\Support\RebuiltServices;
use Foresi\Tests\Inbox\Support\ScriptedTransaction;
use Foresi\Tests\Shared\Infrastructure\Mcp\RecordingLogger;
use Foresi\Timesheet\Domain\TimeEntry;
use Foresi\Work\Application\ManageProjects;
use Foresi\Work\Domain\Task;

/**
 * The owner decides on the suggestions on an item, in a session: accepting
 * runs the steps in their order as the person, each in its own transaction
 * with the suggestion's correlation id, stops at the first that fails and
 * runs only the rest on a retry; rejecting runs nothing. The last decision
 * marks the item done; discarding or marking it done decides what is open.
 */
final class DecideSuggestionsTest extends InboxTestCase
{
    use RebuiltServices;

    private const string NOTE = 'inbox.actions.fixture_note';
    private const string QUOTE = 'please find our offer';

    private string $itemId;
    private ProposingClient $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = new ProposingClient(Id::generate(), 'Mail agent');
        $this->itemId = $this->receive(InboxMail::plain($this->address, body: "Hello,\r\nplease find our offer below.\r\n"));
    }

    public function testAThreeStepSuggestionRunsInOrderAndResolvesTheTaskReference(): void
    {
        $project = self::getContainer()->get(ManageProjects::class)->create('Office move', null);
        $call = $this->activityType('call');
        $suggestion = $this->proposed([
            ['operation' => 'inbox.actions.create_task', 'input' => ['project_id' => $project->id(), 'title' => 'Answer the offer']],
            ['operation' => 'inbox.actions.log_time', 'input' => ['project_id' => $project->id(), 'task_id' => ['$step' => 1, 'field' => 'id'], 'worked_on' => '2026-11-25', 'minutes' => 30]],
            ['operation' => 'inbox.actions.log_activity', 'input' => ['type_id' => $call, 'subject' => 'Call about the offer', 'done' => true]],
        ]);

        [$decision] = $this->accept([$suggestion]);

        self::assertNull($decision->refused);
        $stored = $this->stored($suggestion);
        self::assertSame([null, null, null], array_map(static fn (SuggestionStep $step): ?array => $step->refusal(), $stored->steps()));
        self::assertSame(SuggestionState::Accepted, $stored->state());
        self::assertSame([[1, StepState::Done, 'task'], [2, StepState::Done, 'time_entry'], [3, StepState::Done, 'activity']], array_map(static fn (SuggestionStep $step): array => [$step->position(), $step->state(), $step->recordKind()], $stored->steps()));
        $taskId = $stored->step(1)->recordId();
        $task = $this->em->find(Task::class, $taskId);
        self::assertInstanceOf(Task::class, $task);
        self::assertSame('Answer the offer', $task->title());
        $entry = $this->em->find(TimeEntry::class, $stored->step(2)->recordId());
        self::assertInstanceOf(TimeEntry::class, $entry);
        self::assertSame([$taskId, 30], [$entry->task()?->id(), $entry->minutes()], 'The reference took the task step 1 made.');
        self::assertInstanceOf(Activity::class, $this->em->find(Activity::class, $stored->step(3)->recordId()));
        // The final input keeps the reference as proposed.
        self::assertSame(['$step' => 1, 'field' => 'id'], $stored->step(2)->finalInput()['task_id'] ?? null);
        self::assertSame(ItemState::Done, $this->item($this->itemId)->state(), 'Its last open suggestion is decided.');
        self::assertSame([['suggestion_id' => $suggestion, 'item_id' => $this->itemId]], $this->eventData('inbox.suggestion.accepted'));
    }

    public function testRecordsMadeByASuggestionCarryItsCorrelationId(): void
    {
        $project = self::getContainer()->get(ManageProjects::class)->create('Office move', null);
        $suggestion = $this->proposed([['operation' => 'inbox.actions.create_task', 'input' => ['project_id' => $project->id(), 'title' => 'Answer the offer']]]);

        $this->accept([$suggestion]);

        $taskId = $this->stored($suggestion)->step(1)->recordId();
        self::assertIsString($taskId);
        $entries = $this->em->getRepository(AuditEntry::class)->findBy(['entityClass' => Task::class, 'entityId' => $taskId]);
        self::assertNotSame([], $entries);
        foreach ($entries as $entry) {
            self::assertSame([StepRunner::correlation($suggestion), $this->owner->id()], [$entry->correlationId(), $entry->userId()], 'Made by the accepting person, under the suggestion.');
        }
        // The suggestion's own change is in the trail under it too.
        $own = $this->em->getRepository(AuditEntry::class)->findBy(['rootId' => $suggestion, 'correlationId' => StepRunner::correlation($suggestion)]);
        self::assertNotSame([], $own);
    }

    public function testAFailingMiddleStepStopsTheRestAndRetryRunsOnlyTheRest(): void
    {
        $suggestion = $this->proposed([$this->note('First'), $this->note('Second', fail: true), $this->note('Third')]);

        [$decision] = $this->accept([$suggestion]);

        self::assertNull($decision->refused);
        $stored = $this->stored($suggestion);
        self::assertSame(SuggestionState::Proposed, $stored->state());
        self::assertSame([StepState::Done, StepState::Failed, StepState::Pending], $this->states($stored));
        self::assertSame(['reason' => 'fixture_failed', 'field' => 'input.fail'], $stored->step(2)->refusal());
        self::assertSame(['First'], $this->notes());
        self::assertSame(ItemState::New, $this->item($this->itemId)->state());

        // Retry with the failing step corrected: only it and the rest run.
        $this->accept([$suggestion], [$suggestion => [2 => ['text' => 'Second']]]);

        $stored = $this->stored($suggestion);
        self::assertSame([StepState::Done, StepState::Done, StepState::Done], $this->states($stored));
        self::assertSame(SuggestionState::Accepted, $stored->state());
        self::assertSame(['First', 'Second', 'Third'], $this->notes(), 'The first step made one note, not two.');
    }

    public function testAcceptSelectedRunsEachSuggestionOnItsOwn(): void
    {
        $failing = $this->proposed([$this->note('Fails', fail: true)]);
        $passing = $this->proposed([$this->note('Passes')]);
        $rejected = $this->proposed([$this->note('Rejected')]);
        $this->decide()->reject($rejected, null);

        $decisions = $this->accept([$failing, $passing, $rejected, Id::generate()]);

        self::assertSame([[null, 'proposed'], [null, 'accepted'], ['not_open', 'rejected'], ['not_found', null]], array_map(static fn (Decision $decision): array => [$decision->refused, $decision->suggestion?->state()->value], $decisions));
        self::assertSame(['Passes'], $this->notes(), 'One failing stops none of the others.');
        self::assertSame(ItemState::New, $this->item($this->itemId)->state(), 'One is still open.');
    }

    public function testAnEditedAcceptKeepsTheProposedAndTheFinalInput(): void
    {
        $suggestion = $this->proposed([$this->note('Call Client A back')]);

        $this->accept([$suggestion], [$suggestion => ['1' => ['text' => 'Write to Client A', 'note_kind' => 'call']]]);

        $step = $this->stored($suggestion)->step(1);
        self::assertSame(['text' => 'Call Client A back'], $step->proposedInput());
        self::assertSame(['text' => 'Write to Client A', 'note_kind' => 'call'], $step->finalInput());
        self::assertSame(['Write to Client A'], $this->notes());
        // The trail holds both: the proposed input in the step's creation, the final one in its change.
        $changes = array_map(static fn (AuditEntry $entry): array => $entry->changes(), $this->em->getRepository(AuditEntry::class)->findBy(['entityClass' => SuggestionStep::class, 'entityId' => $step->id()], ['occurredAt' => 'ASC']));
        self::assertTrue(array_any($changes, static fn (array $change): bool => \array_key_exists('proposedInput', $change)));
        self::assertTrue(array_any($changes, static fn (array $change): bool => \array_key_exists('finalInput', $change)));
    }

    public function testAnInvalidEditRefusesTheWholeCallBeforeAnythingRuns(): void
    {
        $first = $this->proposed([$this->note('First')]);
        $second = $this->proposed([$this->note('Second'), $this->note('Third')]);

        $this->assertRefusedAt('invalid_input', 'edits.'.$second.'.2.noteKind', fn (): array => $this->accept([$first, $second], [$second => [2 => ['text' => 'Third', 'note_kind' => 'fax']]]));
        $this->assertRefusedAt('invalid_input', 'edits.'.$second.'.2.markDone', fn (): array => $this->accept([$first, $second], [$second => [2 => ['text' => 'Third', 'mark_done' => true]]]));
        $this->assertRefusedAt('invalid_reference', 'edits.'.$second.'.1.text', fn (): array => $this->accept([$first, $second], [$second => [1 => ['text' => ['$step' => 2, 'field' => 'id']]]]));
        $this->assertRefusedAt('invalid_input', 'edits.'.$second.'.3', fn (): array => $this->accept([$first, $second], [$second => [3 => ['text' => 'None']]]));
        $this->assertRefusedAt('invalid_input', 'edits', fn (): array => $this->accept([$second], [$first => [1 => ['text' => 'Not named']]]));
        $this->assertRefusedAt('invalid_input', 'unselected.'.$first, fn (): array => $this->accept([$first], unselected: [$first => [1]]));
        $this->assertRefusedAt('invalid_input', 'unselected.'.$second.'.0', fn (): array => $this->accept([$second], [$second => [1 => ['text' => 'Edited']]], [$second => [1]]));
        $this->assertRefusedAt('invalid_input', 'suggestionIds', fn (): array => $this->accept([]));
        $this->assertRefusedAt('too_many', 'suggestionIds', fn (): array => $this->accept(array_map(Id::generate(...), range(1, 11))));

        self::assertSame([], $this->notes());
        self::assertSame([SuggestionState::Proposed, SuggestionState::Proposed], [$this->stored($first)->state(), $this->stored($second)->state()]);
        self::assertNull($this->stored($second)->step(2)->finalInput(), 'No edit is stored.');
    }

    public function testUnselectedStepsAreSkipped(): void
    {
        $suggestion = $this->proposed([$this->note('First'), $this->note('Second'), $this->note('Third')]);

        $this->accept([$suggestion], unselected: [$suggestion => [2]]);

        $stored = $this->stored($suggestion);
        self::assertSame([StepState::Done, StepState::Skipped, StepState::Done], $this->states($stored));
        self::assertFalse($stored->step(2)->isSelected());
        self::assertSame(SuggestionState::Accepted, $stored->state());
        self::assertSame(['First', 'Third'], $this->notes());
    }

    public function testAReferenceToAnUnselectedStepFailsUnresolved(): void
    {
        $this->contact('person', 'Client A', self::CLIENT);
        $itemId = $this->receive(InboxMail::plain($this->address, messageId: 'offer-2@client-a.example.com', body: "Hello,\r\nplease find our offer again.\r\n"));
        $suggestion = $this->proposeOn($itemId, [$this->note('First'), ['operation' => 'inbox.actions.fixture_contact_note', 'input' => ['text' => 'Second', 'contact_id' => ['$step' => 1, 'field' => 'id']]], $this->note('Third')]);

        $this->accept([$suggestion], unselected: [$suggestion => [1]]);

        $stored = $this->stored($suggestion);
        self::assertSame([StepState::Skipped, StepState::Failed, StepState::Pending], $this->states($stored));
        self::assertSame(['reason' => 'reference_unresolved', 'field' => 'input.contactId'], $stored->step(2)->refusal());
        self::assertSame([], $this->notes());
    }

    public function testAcceptingTheLastOpenSuggestionMarksTheItemDoneAndOneOfTwoDoesNot(): void
    {
        $first = $this->proposed([$this->note('First')]);
        $second = $this->proposed([$this->note('Second')]);

        $this->accept([$first]);
        self::assertSame(ItemState::New, $this->item($this->itemId)->state(), 'Another suggestion is open.');
        self::assertSame([], $this->eventData('inbox.item.done'));

        $this->accept([$second]);
        self::assertSame(ItemState::Done, $this->item($this->itemId)->state());
        self::assertCount(1, $this->eventData('inbox.item.done'));
    }

    public function testRejectingTheLastOpenOneMarksTheItemDoneOnlyAfterAnAccept(): void
    {
        $first = $this->proposed([$this->note('First')]);
        $second = $this->proposed([$this->note('Second')]);

        $rejected = $this->decide()->reject($first, '  Not needed  ');
        self::assertSame([SuggestionState::Rejected, 'Not needed', $this->owner->id()], [$rejected->state(), $rejected->rejectReason(), $rejected->decidedBy()]);
        self::assertSame([['suggestion_id' => $first, 'item_id' => $this->itemId]], $this->eventData('inbox.suggestion.rejected'));
        self::assertSame(ItemState::New, $this->item($this->itemId)->state(), 'Nothing was accepted.');

        $this->assertRefusedAt('not_open', null, fn (): Suggestion => $this->decide()->reject($first, null));
        $this->assertRefusedAt('not_found', null, fn (): Suggestion => $this->decide()->reject(Id::generate(), null));
        $this->assertRefusedAt('invalid_input', 'reason', fn (): Suggestion => $this->decide()->reject($second, str_repeat('a', 501)));

        $this->accept([$second]);
        self::assertSame(ItemState::Done, $this->item($this->itemId)->state());
        self::assertSame(['Second'], $this->notes(), 'Only the accepted one ran.');
    }

    public function testAStepNeverRunsTwiceUnderTwoAcceptsInARow(): void
    {
        $suggestion = $this->proposed([$this->note('First')]);

        $this->accept([$suggestion]);
        [$again] = $this->accept([$suggestion]);
        self::assertSame('not_open', $again->refused);

        // The runner itself never runs a done step again.
        self::assertSame(StepState::Done, self::getContainer()->get(StepRunner::class)->run($suggestion, 1));
        self::assertSame(['First'], $this->notes());
    }

    public function testAnAcceptedSuggestionCannotBeReplacedAfterwards(): void
    {
        $suggestion = $this->proposed([$this->note('First')]);
        $this->accept([$suggestion]);
        $this->writes()->reopen(new \Foresi\Inbox\Application\Api\ReopenItemInput($this->itemId));

        $this->assertRefusedAt('not_open', 'suggestions.0.replaces.0', fn (): array => self::getContainer()->get(ProposeSuggestions::class)->propose($this->itemId, [['steps' => [$this->note('Second')], 'rationale' => 'Why', 'quote' => self::QUOTE, 'replaces' => [$suggestion]]], $this->agent));
        self::assertSame(SuggestionState::Accepted, $this->stored($suggestion)->state());
    }

    public function testAnEditWithANulIsRefused(): void
    {
        $suggestion = $this->proposed([$this->note('First')]);

        $this->assertRefusedAt('invalid_input', 'edits.'.$suggestion.'.1.text', fn (): array => $this->accept([$suggestion], [$suggestion => [1 => ['text' => "A\0"]]]));
        $this->assertRefusedAt('invalid_input', 'reason', fn (): Suggestion => $this->decide()->reject($suggestion, "No\0"));
    }

    public function testAStepThatMadeItsRecordAndThenRefusedLeavesNothing(): void
    {
        $suggestion = $this->proposed([['operation' => self::NOTE, 'input' => ['text' => 'A note', 'fail_after' => true]]]);
        $events = $this->eventCount();

        [$decision] = $this->accept([$suggestion]);

        self::assertNull($decision->refused);
        $step = $this->stored($suggestion)->step(1);
        self::assertSame([StepState::Failed, ['reason' => 'fixture_failed', 'field' => 'input.failAfter']], [$step->state(), $step->refusal()]);
        $this->assertNothingRemains($events);
        // The same contact made outside a step is stored with its event, so the zero above means something.
        self::getContainer()->get(\Foresi\Contacts\Contract\ContactCreation::class)->create(new \Foresi\Contacts\Contract\NewContact('person', \Foresi\Tests\Inbox\Fixtures\Action\FixtureNoteHandler::MADE_BEFORE_FAILING, 'Example Street 2', 'Example City', '110 00', 'CZ', '', email: 'made.before@example.com'));
        self::assertSame([1, 1], [$this->partiesNamed(), $this->eventsNamedLike('contacts.contact')]);
    }

    public function testAStepThatMadeItsRecordAndThenFailedLeavesNothingAndTheNextSuggestionRuns(): void
    {
        $exploding = $this->proposed([['operation' => self::NOTE, 'input' => ['text' => 'The secret input', 'explode' => true]]]);
        $next = $this->proposed([$this->note('Next')]);
        $events = $this->eventCount();

        $decisions = $this->accept([$exploding, $next]);

        $step = $this->stored($exploding)->step(1);
        self::assertSame([StepState::Failed, ['reason' => StepRunner::INTERNAL_ERROR, 'field' => null]], [$step->state(), $step->refusal()]);
        self::assertStringNotContainsString('secret', (string) json_encode($step->refusal()));
        self::assertSame(['proposed', 'accepted'], array_map(static fn (Decision $decision): ?string => $decision->suggestion?->state()->value, $decisions));
        self::assertSame(['Next'], $this->notes(), 'The next suggestion still ran.');
        self::assertSame(0, $this->partiesNamed());
        // Only the next suggestion's output and events were committed: none of the contact's.
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM inbox_item_output'));
        self::assertGreaterThan($events, $this->eventCount());
        self::assertSame(0, $this->eventsNamedLike('contacts.contact'));
    }

    public function testAFailedThenRetriedSuggestionRunsItsDoneStepOnce(): void
    {
        $suggestion = $this->proposed([$this->note('First'), $this->note('Second', fail: true)]);
        $this->accept([$suggestion]);
        self::assertSame([StepState::Done, StepState::Failed], $this->states($this->stored($suggestion)));

        // On an open suggestion the runner itself skips the done step, at the step guard.
        self::assertSame(StepState::Done, self::getContainer()->get(StepRunner::class)->run($suggestion, 1));
        $this->accept([$suggestion]);
        self::assertSame([StepState::Done, StepState::Failed], $this->states($this->stored($suggestion)));
        $this->accept([$suggestion], [$suggestion => [2 => ['text' => 'Second']]]);
        [$again] = $this->accept([$suggestion]);

        self::assertSame('not_open', $again->refused);
        self::assertSame(['First', 'Second'], $this->notes(), 'Step 1 made one note across four accepts.');
    }

    public function testTheRunnerReadsTheStepAgainOnceItIsLocked(): void
    {
        // SQLite ignores FOR UPDATE: this pins the re-read under the lock, not the database lock.
        $suggestion = $this->proposed([$this->note('First'), $this->note('Second')]);
        $loaded = self::getContainer()->get(SuggestionStore::class)->find($suggestion);
        self::assertInstanceOf(Suggestion::class, $loaded);
        self::assertSame(StepState::Pending, $loaded->step(1)->state());
        $this->em->getConnection()->executeStatement("UPDATE inbox_suggestion_step SET state = 'done', record_kind = ?, record_id = ? WHERE suggestion_id = ? AND position = 1", [FixtureNotes::KIND, Id::generate(), $suggestion]);

        self::assertSame(StepState::Done, self::getContainer()->get(StepRunner::class)->run($suggestion, 1));
        self::assertSame([], $this->notes(), 'Done by another accept meanwhile: it does not run again.');
    }

    public function testADirectActionLeavesTheItemNewWhileSuggestionsWait(): void
    {
        $this->proposed([$this->note('Proposed')]);
        $outputs = self::getContainer()->get(RecordOutputs::class);
        $action = self::getContainer()->get(InboxActions::class)->get(self::NOTE);
        self::assertNotNull($action);
        $input = \Foresi\Shared\Infrastructure\Operation\InputMapper::map($action->definition, ['inbox_item_id' => $this->itemId, 'text' => 'Direct']);
        self::assertInstanceOf(\Foresi\Inbox\Contract\InboxActionInput::class, $input);

        $outputs->check($action->action, $input, false);
        self::assertTrue($outputs->record($action->action, $input, $action->definition->handler->handle($input)));

        self::assertTrue($outputs->keptNew($input));
        self::assertSame(ItemState::New, $this->item($this->itemId)->state(), 'mark_done waits for the last decision.');
        self::assertSame([], $this->eventData('inbox.item.done'));
    }

    public function testAddToBudgetRunsAsAStep(): void
    {
        $client = $this->contact('person', 'Client A', self::CLIENT);
        $project = self::getContainer()->get(ManageProjects::class)->create('Office move', $client);
        // Received after the contact exists: matched, so the client's active project offers the action.
        $itemId = $this->receive(InboxMail::plain($this->address, messageId: 'offer-5@client-a.example.com', body: "Hello,\r\nplease find our offer again.\r\n"));
        $suggestion = $this->proposeOn($itemId, [['operation' => 'inbox.actions.add_to_budget', 'input' => ['project_id' => $project->id(), 'hours' => '8', 'note' => 'Approved by Client A']]]);

        $this->accept([$suggestion]);

        $step = $this->stored($suggestion)->step(1);
        self::assertSame([StepState::Done, null, 'project_budget_change'], [$step->state(), $step->refusal(), $step->recordKind()], 'The link the step adds is seen by the check that follows it.');
        $activityId = $this->item($itemId)->emailActivityId();
        self::assertIsString($activityId);
        self::assertSame($activityId, $this->scalar('SELECT source_activity_id FROM project_budget_change WHERE id = ?', [$step->recordId()]));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM project_budget_change'));
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM activities_activity_link WHERE activity_id = ? AND kind = 'project' AND target_id = ?", [$activityId, $project->id()]));
    }

    public function testCreateDealRunsAsAStep(): void
    {
        // The stage the deals migration seeds, which a schema built from the mapping lacks.
        $this->em->persist(new \Foresi\Deals\Domain\Stage('inquiry', null, 0, $this->clock->now()));
        $this->em->flush();
        $client = $this->contact('person', 'Client A', self::CLIENT);
        // Received after the contact exists, so it is matched and the deal action applies.
        $itemId = $this->receive(InboxMail::plain($this->address, messageId: 'offer-4@client-a.example.com', body: "Hello,\r\nplease find our offer again.\r\n"));
        $suggestion = $this->proposeOn($itemId, [['operation' => 'inbox.actions.create_deal', 'input' => ['title' => 'Office move offer', 'client_party_id' => $client]]]);

        $this->accept([$suggestion]);

        $step = $this->stored($suggestion)->step(1);
        self::assertSame([StepState::Done, null], [$step->state(), $step->refusal()]);
        self::assertSame('Office move offer', $this->scalar('SELECT title FROM deal WHERE id = ?', [$step->recordId()]));
    }

    public function testAnotherPersonsSuggestionIsNotFound(): void
    {
        $suggestion = $this->proposed([$this->note('First')]);
        $this->signIn($this->person('other@example.com', Role::Member));

        [$decision] = $this->accept([$suggestion]);

        self::assertSame(['not_found', null], [$decision->refused, $decision->suggestion]);
        $this->assertRefusedAt('not_found', null, fn (): Suggestion => $this->decide()->reject($suggestion, null));
        self::assertSame([], $this->notes());
    }

    public function testDiscardingTheItemRejectsOpenSuggestions(): void
    {
        $open = $this->proposed([$this->note('First')]);
        $accepted = $this->proposed([$this->note('Second')]);
        $this->accept([$accepted]);

        $this->writes()->discard(new DiscardItemInput($this->itemId));

        $stored = $this->stored($open);
        self::assertSame([SuggestionState::Rejected, null, $this->owner->id()], [$stored->state(), $stored->rejectReason(), $stored->decidedBy()]);
        self::assertSame(SuggestionState::Accepted, $this->stored($accepted)->state());
        self::assertSame([['suggestion_id' => $open, 'item_id' => $this->itemId]], $this->eventData('inbox.suggestion.rejected'));
        self::assertSame(ItemState::Discarded, $this->item($this->itemId)->state());
    }

    public function testDoneWithOpenSuggestionsAsks(): void
    {
        $first = $this->proposed([$this->note('First')]);

        $this->assertRefusedAt('open_suggestions', null, fn (): mixed => $this->writes()->done(new MarkItemDoneInput($this->itemId)));
        $asked = $this->item($this->itemId);
        self::assertSame(ItemState::New, $asked->state());

        $this->writes()->done(new MarkItemDoneInput($this->itemId, MarkItemDoneInput::KEEP));
        $kept = $this->item($this->itemId);
        self::assertSame([ItemState::Done, SuggestionState::Proposed], [$kept->state(), $this->stored($first)->state()]);

        // A kept suggestion on the done item can still be accepted.
        $this->accept([$first]);
        self::assertSame(SuggestionState::Accepted, $this->stored($first)->state());

        $other = $this->receive(InboxMail::plain($this->address, messageId: 'offer-3@client-a.example.com', body: "Hello,\r\nplease find our offer again.\r\n"));
        $second = $this->proposeOn($other, [$this->note('Second')]);
        $this->writes()->done(new MarkItemDoneInput($other, MarkItemDoneInput::REJECT));
        self::assertSame([ItemState::Done, SuggestionState::Rejected], [$this->item($other)->state(), $this->stored($second)->state()]);
        $this->assertRefusedAt('invalid_input', 'suggestions', fn (): mixed => $this->writes()->done(new MarkItemDoneInput($other, 'later')));

        // Without suggestions nothing is asked.
        $plain = $this->receive(InboxMail::plain($this->address, subject: 'Something else', messageId: 'other-1@client-a.example.com'));
        self::assertSame(ItemState::Done, $this->writes()->done(new MarkItemDoneInput($plain))->state());
    }

    public function testARejectReasonIsCountedByCharactersAfterTheTrim(): void
    {
        foreach ([str_repeat('é', 500), '  '.str_repeat('a', 500).'  '] as $reason) {
            $rejected = $this->decide()->reject($this->proposed([$this->note('First')]), $reason);
            self::assertSame(trim($reason), $rejected->rejectReason());
        }
        $this->assertRefusedAt('invalid_input', 'reason', fn (): Suggestion => $this->decide()->reject($this->proposed([$this->note('Second')]), str_repeat('é', 501)));
        $this->assertRefusedAt('invalid_input', 'reason', fn (): Suggestion => $this->decide()->reject($this->proposed([$this->note('Third')]), '  '.str_repeat('a', 501)));
    }

    public function testRejectingAnItemInTheTrashIsRefused(): void
    {
        $suggestion = $this->proposed([$this->note('First')]);
        $this->writes()->trash(new TrashItemInput($this->itemId));

        $this->assertRefusedAt('item_trashed', null, fn (): Suggestion => $this->decide()->reject($suggestion, null));
        self::assertSame(SuggestionState::Proposed, $this->stored($suggestion)->state());
    }

    public function testTheShapeOfTheIdsAndTheLimitAreChecked(): void
    {
        $first = $this->proposed([$this->note('First')]);

        $this->assertRefusedAt('invalid_input', 'suggestionIds.0', fn (): array => $this->accept([5]));
        $this->assertRefusedAt('invalid_input', 'suggestionIds.1', fn (): array => $this->accept([$first, '']));
        $ten = array_map(Id::generate(...), range(1, 10));
        self::assertSame(array_fill(0, 10, 'not_found'), array_map(static fn (Decision $decision): ?string => $decision->refused, $this->accept($ten)));
        self::assertCount(1, $this->accept([$first, $first]), 'A suggestion named twice is decided once.');
        try {
            $this->accept(array_map(Id::generate(...), range(1, 11)));
            self::fail('Expected a refusal.');
        } catch (InboxRefused $refused) {
            self::assertSame(['too_many', 'suggestionIds', ['max' => 10]], [$refused->reason(), $refused->field(), $refused->details()]);
        }
    }

    public function testTheShapeOfTheEditsAndTheUntickedStepsIsChecked(): void
    {
        $first = $this->proposed([$this->note('First'), $this->note('Second')]);
        $second = $this->proposed([$this->note('Third')]);
        $invalid = fn (string $field, ?array $edits, ?array $unselected = null) => $this->assertRefusedAt('invalid_input', $field, fn (): array => $this->accept([$first, $second], $edits, $unselected));

        $invalid('edits', [['text' => 'A list']]);
        $invalid('unselected', null, [[1]]);
        $invalid('edits.'.$first, [$first => 'text']);
        $invalid('edits.'.$first, [$first => [['text' => 'A list']]]);
        $invalid('edits.'.$first.'.1', [$first => [1 => 'text']]);
        $invalid('edits.'.$first.'.3', [$first => [3 => ['text' => 'None']]]);
        foreach (['1x', ' 1', '01', '+1', '1.0'] as $key) {
            $invalid('edits.'.$first.'.*', [$first => [$key => ['text' => 'Odd key']]]);
        }
        $invalid('unselected.'.$first, null, [$first => 'text']);
        $invalid('unselected.'.$first, null, [$first => ['a' => 1]]);
        foreach (['1x', ' 1', '01', 'x1', '+1', "1\n", 1.5, 0, 3, -1, true, null, [1]] as $position) {
            $invalid('unselected.'.$first.'.0', null, [$first => [$position]]);
        }
        $invalid('unselected.'.$first.'.1', null, [$first => [1, 9]]);
        self::assertSame([], $this->notes());
        self::assertSame([SuggestionState::Proposed, SuggestionState::Proposed], [$this->stored($first)->state(), $this->stored($second)->state()]);
    }

    public function testEditsAndUntickedStepsOfSeveralSuggestionsAndOfNumberedStringsApply(): void
    {
        $first = $this->proposed([$this->note('First'), $this->note('Second'), $this->note('Third')]);
        $second = $this->proposed([$this->note('Fourth')]);

        $this->accept([$first, $second], [$first => [1 => ['text' => 'First, edited']], $second => [1 => ['text' => 'Fourth, edited']]], [$first => ['2', 3, '3']]);

        self::assertSame(['First, edited', 'Fourth, edited'], $this->notes());
        self::assertSame([StepState::Done, StepState::Skipped, StepState::Skipped], $this->states($this->stored($first)));
        self::assertSame(SuggestionState::Accepted, $this->stored($second)->state());
    }

    public function testATrashedItemAndAClosedSuggestionAreDecidedBeforeTheirEditsAreChecked(): void
    {
        $closed = $this->proposed([$this->note('Closed')]);
        $open = $this->proposed([$this->note('Open')]);
        $this->decide()->reject($closed, null);

        // Leaving nothing ticked would refuse the whole call, were the closed suggestion planned.
        [$decision] = $this->accept([$closed], null, [$closed => [1]]);
        self::assertSame('not_open', $decision->refused);

        $this->writes()->trash(new TrashItemInput($this->itemId));
        [$decision] = $this->accept([$open]);
        self::assertSame(['item_trashed', SuggestionState::Proposed], [$decision->refused, $decision->suggestion?->state()]);
        self::assertSame([], $this->notes());
    }

    public function testAStepWhoseActionIsGoneIsRefusedAtTheEditAndFailedAtTheRun(): void
    {
        $suggestion = $this->proposed([$this->note('First')]);
        $this->em->getConnection()->executeStatement("UPDATE inbox_suggestion_step SET operation = 'inbox.actions.gone' WHERE suggestion_id = ?", [$suggestion]);
        $this->em->clear();

        $this->assertRefusedAt('action_not_applicable', 'edits.'.$suggestion.'.1', fn (): array => $this->accept([$suggestion], [$suggestion => [1 => ['text' => 'Edited']]]));

        [$decision] = $this->accept([$suggestion]);

        self::assertNull($decision->refused);
        $step = $this->stored($suggestion)->step(1);
        self::assertSame([StepState::Failed, ['reason' => 'action_not_applicable', 'field' => 'operation']], [$step->state(), $step->refusal()]);
    }

    public function testASuggestionDecidedBySomeoneElseOrFailingForGoodIsReportedAndStopsNoOtherOne(): void
    {
        $first = $this->proposed([$this->note('First')]);
        $second = $this->proposed([$this->note('Second')]);
        $logger = new RecordingLogger();
        $transaction = new ScriptedTransaction(self::getContainer()->get(\Foresi\Inbox\Application\InboxTransaction::class));
        $decide = $this->rebuilt($this->decide(), ['transaction' => $transaction->failing(1, InboxRefused::itemTrashed())->failing(3, new \RuntimeException('A secret text of the mail')), 'logger' => $logger]);

        [$refused, $failed] = $decide->accept([$first, $second]);

        self::assertSame(['item_trashed', SuggestionState::Proposed], [$refused->refused, $refused->suggestion?->state()]);
        self::assertSame([StepRunner::INTERNAL_ERROR, SuggestionState::Proposed], [$failed->refused, $failed->suggestion?->state()]);
        self::assertSame([['error', 'An inbox suggestion could not be decided.', ['suggestion_id' => $second, 'failure' => \RuntimeException::class]]], $logger->records);
    }

    public function testEveryLockIsTakenInTheOrderOfTheItemThenItsSuggestions(): void
    {
        $first = $this->proposed([$this->note('First')]);
        $second = $this->proposed([$this->note('Second')]);
        $item = 'item '.$this->itemId;

        [$decide, $log] = $this->locking(DecideSuggestions::class);
        $decide->reject($first, null);
        self::assertSame([$item, 'suggestion '.$first, $item], $log->locks, 'Rejected: the decision, then the item once more to close it.');

        [$decide, $log] = $this->locking(DecideSuggestions::class);
        $decide->accept([$second]);
        $this->em->clear();
        self::assertSame([$item, 'suggestion '.$second, $item, 'suggestion '.$second, $item, 'suggestion '.$second, $item], $log->locks, 'Accepted: the edits, the step, the end, and the item to close it.');
        self::assertSame([false], $this->fixtureNotes()->markDone, 'A step leaves marking the item done to the last decision.');
    }

    public function testDiscardingLocksTheOpenSuggestionsByIdAfterTheItem(): void
    {
        $oldest = $this->proposed([$this->note('Oldest')]);
        $this->clock->modify('-1 hour');
        $newest = $this->proposed([$this->note('Newest')]);
        $accepted = $this->proposed([$this->note('Accepted')]);
        $this->accept([$accepted]);
        [$writes, $log] = $this->locking(ItemWrites::class);
        $ids = [$oldest, $newest];
        sort($ids);

        $writes->discard(new DiscardItemInput($this->itemId));

        self::assertSame(['item '.$this->itemId, 'suggestion '.$ids[0], 'suggestion '.$ids[1]], $log->locks, 'By id, though proposed in the other order; the accepted one is not locked.');
        self::assertSame(SuggestionState::Rejected, $this->stored($oldest)->state());
    }

    public function testAStepThatFailsOnASuggestionDecidedMeanwhileStoresNothing(): void
    {
        $suggestion = $this->proposed([$this->note('First', fail: true)]);
        $runner = $this->rebuilt(self::getContainer()->get(StepRunner::class), ['transaction' => new ScriptedTransaction(self::getContainer()->get(\Foresi\Inbox\Application\InboxTransaction::class))->before(2, fn (): Suggestion => $this->decide()->reject($suggestion, null))]);

        self::assertSame(StepState::Failed, $runner->run($suggestion, 1));

        $stored = $this->stored($suggestion);
        self::assertSame([SuggestionState::Rejected, StepState::Pending, null], [$stored->state(), $stored->step(1)->state(), $stored->step(1)->refusal()]);
    }

    public function testAStepThatBreaksIsLoggedByItsIdentityAndTheClassOnly(): void
    {
        $suggestion = $this->proposed([['operation' => self::NOTE, 'input' => ['text' => 'The secret input', 'explode' => true]]]);
        $logger = new RecordingLogger();
        $runner = $this->rebuilt(self::getContainer()->get(StepRunner::class), ['logger' => $logger]);

        self::assertSame(StepState::Failed, $runner->run($suggestion, 1));

        self::assertSame([['error', 'A step of an inbox suggestion failed.', ['step' => 'inbox:suggestion:'.$suggestion.':step:1', 'failure' => \RuntimeException::class]]], $logger->records);
        self::assertSame('inbox:suggestion:'.$suggestion, StepRunner::correlation($suggestion));
    }

    public function testWhatMarkDoneLeavesNewIsToldOnlyWhileASuggestionWaitsOnANewItem(): void
    {
        $outputs = self::getContainer()->get(RecordOutputs::class);
        $asked = fn (string $itemId, bool $markDone): bool => $outputs->keptNew(new FixtureNoteInput($itemId, 'Direct', markDone: $markDone));

        self::assertFalse($asked($this->itemId, true), 'No suggestion waits.');
        $this->proposed([$this->note('First')]);
        self::assertTrue($asked($this->itemId, true));
        self::assertFalse($asked($this->itemId, false), 'mark_done did not ask.');
        self::assertFalse($asked(Id::generate(), true), 'An item that is not theirs.');
        $this->writes()->done(new MarkItemDoneInput($this->itemId, MarkItemDoneInput::KEEP));
        self::assertFalse($asked($this->itemId, true), 'The item is done already.');
    }

    public function testTheAnswerOfADirectActionSaysTheItemStaysNew(): void
    {
        $this->proposed([$this->note('Waiting')]);
        $outputs = self::getContainer()->get(RecordOutputs::class);
        $action = self::getContainer()->get(InboxActions::class)->get(self::NOTE);
        self::assertNotNull($action);
        $output = new OperationOutput(['id' => $this->fixtureNotes()->make('Direct')], 'Made the note.');

        $answer = $outputs->after($action->definition->operation, new FixtureNoteInput($this->itemId, 'Direct', markDone: true), $output);

        self::assertSame('Made the note. '.RecordOutputs::KEPT_NEW, $answer->summary);
        self::assertSame($output->data, $answer->data);
    }

    /**
     * A use case of the container with the stores that note the locks they are asked for.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return array{T, LockLog}
     */
    private function locking(string $class): array
    {
        $log = new LockLog();
        $items = new LockRecordingItems(new DoctrineItemStore($this->em), $log);
        $suggestions = new LockRecordingSuggestions(new DoctrineSuggestionStore($this->em), $log);
        $replace = ItemWrites::class === $class ? ['store' => $items, 'suggestions' => $suggestions] : ['items' => $items, 'store' => $suggestions];
        if (DecideSuggestions::class === $class) {
            $replace['steps'] = $this->rebuilt(self::getContainer()->get(StepRunner::class), ['items' => $items, 'store' => $suggestions]);
        }

        $service = $this->rebuilt(self::getContainer()->get($class), $replace);
        self::assertInstanceOf($class, $service);

        return [$service, $log];
    }

    /**
     * @param list<mixed>       $ids
     * @param array<mixed>|null $edits
     * @param array<mixed>|null $unselected
     *
     * @return list<Decision>
     */
    private function accept(array $ids, ?array $edits = null, ?array $unselected = null): array
    {
        $decisions = $this->decide()->accept($ids, $edits, $unselected);
        $this->em->clear();

        return $decisions;
    }

    private function decide(): DecideSuggestions
    {
        return self::getContainer()->get(DecideSuggestions::class);
    }

    /**
     * @param list<array<string, mixed>> $steps
     */
    private function proposed(array $steps): string
    {
        return $this->proposeOn($this->itemId, $steps);
    }

    /**
     * @param list<array<string, mixed>> $steps
     */
    private function proposeOn(string $itemId, array $steps): string
    {
        $quote = $itemId === $this->itemId ? self::QUOTE : 'please find our offer again';
        [$suggestion] = self::getContainer()->get(ProposeSuggestions::class)->propose($itemId, [['steps' => $steps, 'rationale' => 'The client asks for it.', 'quote' => $quote]], $this->agent);

        return $suggestion->id();
    }

    /**
     * @return array{operation: string, input: array<string, mixed>}
     */
    private function note(string $text, bool $fail = false): array
    {
        return ['operation' => self::NOTE, 'input' => ['text' => $text] + ($fail ? ['fail' => true] : [])];
    }

    private function stored(string $id): Suggestion
    {
        $this->em->clear();
        $suggestion = self::getContainer()->get(SuggestionStore::class)->find($id);
        self::assertInstanceOf(Suggestion::class, $suggestion);

        return $suggestion;
    }

    /**
     * @return list<StepState>
     */
    private function states(Suggestion $suggestion): array
    {
        return array_map(static fn (SuggestionStep $step): StepState => $step->state(), $suggestion->steps());
    }

    private function activityType(string $key): string
    {
        $type = new ActivityType($key, null, ActivityTiming::None, null, $this->clock->now());
        $this->em->persist($type);
        $this->em->flush();

        return $type->id();
    }

    /**
     * @return list<string> the texts of the notes the fixture action made, in order
     */
    private function notes(): array
    {
        return array_values($this->fixtureNotes()->made);
    }

    private function fixtureNotes(): FixtureNotes
    {
        $notes = self::getContainer()->get(FixtureNotes::class); // @phpstan-ignore symfonyContainer.serviceNotFound (registered in the test environment only, and PHPStan reads the dev container)
        self::assertInstanceOf(FixtureNotes::class, $notes);

        return $notes;
    }

    private function assertNothingRemains(int $events): void
    {
        self::assertSame(0, $this->partiesNamed(), 'The contact the step made is rolled back with it.');
        self::assertSame([], $this->notes());
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM inbox_item_output'));
        self::assertSame($events, $this->eventCount(), 'No event of the failed step is released.');
        self::assertSame(ItemState::New, $this->item($this->itemId)->state());
    }

    private function partiesNamed(): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM party WHERE display_name = ?', [\Foresi\Tests\Inbox\Fixtures\Action\FixtureNoteHandler::MADE_BEFORE_FAILING]);
    }

    private function eventCount(): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM '.\Foresi\Events\Infrastructure\Dbal\EventLogTable::LOG);
    }

    private function eventsNamedLike(string $part): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM '.\Foresi\Events\Infrastructure\Dbal\EventLogTable::LOG.' WHERE '.\Foresi\Events\Infrastructure\Dbal\EventLogTable::NAME.' LIKE ?', ['%'.$part.'%']);
    }

    /**
     * @param list<mixed> $parameters
     */
    private function scalar(string $sql, array $parameters = []): string|int|null
    {
        $value = $this->em->getConnection()->fetchOne($sql, $parameters);
        self::assertTrue(\is_string($value) || \is_int($value) || null === $value || false === $value);

        return false === $value ? null : $value;
    }

    private function assertRefusedAt(string $reason, ?string $field, callable $work): void
    {
        try {
            $work();
        } catch (Refused $refused) {
            self::assertSame([$reason, $field], [$refused->reason(), $refused->field()], $refused->getMessage());

            return;
        }
        self::fail(\sprintf('Expected the refusal %s.', $reason));
    }
}
