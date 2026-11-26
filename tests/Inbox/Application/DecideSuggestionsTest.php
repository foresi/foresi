<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Application;

use Foresi\Activities\Domain\Activity;
use Foresi\Activities\Domain\ActivityTiming;
use Foresi\Activities\Domain\ActivityType;
use Foresi\Audit\Domain\AuditEntry;
use Foresi\Identity\Domain\Role;
use Foresi\Inbox\Application\Api\DiscardItemInput;
use Foresi\Inbox\Application\Api\MarkItemDoneInput;
use Foresi\Inbox\Application\DecideSuggestions;
use Foresi\Inbox\Application\Decision;
use Foresi\Inbox\Application\ProposeSuggestions;
use Foresi\Inbox\Application\ProposingClient;
use Foresi\Inbox\Application\StepRunner;
use Foresi\Inbox\Application\SuggestionStore;
use Foresi\Inbox\Domain\ItemState;
use Foresi\Inbox\Domain\StepState;
use Foresi\Inbox\Domain\Suggestion;
use Foresi\Inbox\Domain\SuggestionState;
use Foresi\Inbox\Domain\SuggestionStep;
use Foresi\Shared\Contract\Refused;
use Foresi\Shared\Domain\Id;
use Foresi\Tests\Inbox\Fixtures\Action\FixtureNotes;
use Foresi\Tests\Inbox\Support\InboxMail;
use Foresi\Tests\Inbox\Support\InboxTestCase;
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
    private const string NOTE = 'inbox.actions.fixture_note';
    private const string QUOTE = 'please find our offer';

    private string $itemId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->itemId = $this->receive(InboxMail::plain($this->address, body: "Hello,\r\nplease find our offer below.\r\n"));
    }

    /**
     * Create a task, then a note about the task step 1 made, then log an activity: real Work and
     * Activities actions around the tests' own. Work's log time cannot be the middle step yet:
     * joined to the step's transaction its answer reads the entry back before it is flushed.
     */
    public function testAThreeStepSuggestionRunsInOrderAndResolvesTheTaskReference(): void
    {
        $project = self::getContainer()->get(ManageProjects::class)->create('Office move', null);
        $call = $this->activityType('call');
        $suggestion = $this->proposed([
            ['operation' => 'inbox.actions.create_task', 'input' => ['project_id' => $project->id(), 'title' => 'Answer the offer']],
            ['operation' => self::NOTE, 'input' => ['text' => 'About the task', 'about_id' => ['$step' => 1, 'field' => 'id']]],
            ['operation' => 'inbox.actions.log_activity', 'input' => ['type_id' => $call, 'subject' => 'Call about the offer', 'done' => true]],
        ]);

        [$decision] = $this->accept([$suggestion]);

        self::assertNull($decision->refused);
        $stored = $this->stored($suggestion);
        self::assertSame([null, null, null], array_map(static fn (SuggestionStep $step): ?array => $step->refusal(), $stored->steps()));
        self::assertSame(SuggestionState::Accepted, $stored->state());
        self::assertSame([[1, StepState::Done, 'task'], [2, StepState::Done, FixtureNotes::KIND], [3, StepState::Done, 'activity']], array_map(static fn (SuggestionStep $step): array => [$step->position(), $step->state(), $step->recordKind()], $stored->steps()));
        $taskId = $stored->step(1)->recordId();
        $task = $this->em->find(Task::class, $taskId);
        self::assertInstanceOf(Task::class, $task);
        self::assertSame('Answer the offer', $task->title());
        self::assertSame([$stored->step(2)->recordId() => $taskId], $this->fixtureNotes()->about, 'The reference took the task step 1 made.');
        self::assertInstanceOf(Activity::class, $this->em->find(Activity::class, $stored->step(3)->recordId()));
        // The final input keeps the reference as proposed.
        self::assertSame(['$step' => 1, 'field' => 'id'], $stored->step(2)->finalInput()['about_id'] ?? null);
        $ran = array_map(static fn (SuggestionStep $step): ?\DateTimeImmutable => $step->ranAt(), $stored->steps());
        self::assertSame($ran, array_values(array_filter($ran)), 'Each step ran.');
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
        $this->assertRefusedAt('invalid_input', 'edits.'.$first, fn (): array => $this->accept([$second], [$first => [1 => ['text' => 'Not named']]]));
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

    /**
     * @param list<string>                     $ids
     * @param array<string, array<mixed>>|null $edits
     * @param array<string, list<int>>|null    $unselected
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
        [$suggestion] = self::getContainer()->get(ProposeSuggestions::class)->propose($itemId, [['steps' => $steps, 'rationale' => 'The client asks for it.', 'quote' => $quote]], new ProposingClient(Id::generate(), 'Mail agent'));

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
