<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Domain;

use Foresi\Inbox\Domain\InboxItem;
use Foresi\Inbox\Domain\StepState;
use Foresi\Inbox\Domain\Suggestion;
use Foresi\Inbox\Domain\SuggestionState;
use Foresi\Inbox\Domain\SuggestionStep;
use Foresi\Inbox\Domain\ThreadKey;
use Foresi\Shared\Domain\Id;
use PHPUnit\Framework\TestCase;

final class SuggestionTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-25 10:00:00', new \DateTimeZone('Europe/Prague'));
    }

    public function testANewSuggestionIsProposedWithItsStepsPendingAndTicked(): void
    {
        $suggestion = $this->suggestion(3);

        self::assertSame(SuggestionState::Proposed, $suggestion->state());
        self::assertTrue($suggestion->isOpen());
        self::assertSame([1, 2, 3], array_map(static fn (SuggestionStep $step): int => $step->position(), $suggestion->steps()));
        foreach ($suggestion->steps() as $step) {
            self::assertSame(StepState::Pending, $step->state());
            self::assertTrue($step->isSelected());
            self::assertNull($step->finalInput());
            self::assertSame($step->proposedInput(), $step->input());
        }
        self::assertSame('UTC', $suggestion->proposedAt()->getTimezone()->getName());
        self::assertCount(3, $suggestion->stepsToRun());
    }

    public function testTheClientNameIsCutTo100Characters(): void
    {
        $suggestion = $this->suggestion(1, client: str_repeat('é', 150));

        self::assertSame(str_repeat('é', 100), $suggestion->proposedByClient());
    }

    #[\PHPUnit\Framework\Attributes\DoesNotPerformAssertions]
    public function testItNeedsOneToTenStepsARationaleAndAQuote(): void
    {
        foreach ([[0, 'Why', 'Quote'], [11, 'Why', 'Quote'], [1, ' ', 'Quote'], [1, str_repeat('a', 2001), 'Quote'], [1, 'Why', ''], [1, 'Why', str_repeat('a', 1001)]] as [$steps, $rationale, $quote]) {
            try {
                $this->suggestion($steps, $rationale, $quote);
                self::fail('Expected a refusal.');
            } catch (\InvalidArgumentException) {
            }
        }
    }

    public function testARejectionIsFinalAndKeepsItsReason(): void
    {
        $suggestion = $this->suggestion(1);
        $userId = Id::generate();

        $suggestion->reject($userId, '  Not for us  ', $this->now);

        self::assertSame(SuggestionState::Rejected, $suggestion->state());
        self::assertSame([$userId, 'Not for us'], [$suggestion->decidedBy(), $suggestion->rejectReason()]);
        self::assertNotNull($suggestion->decidedAt());
        $this->expectException(\LogicException::class);
        $suggestion->reject($userId, null, $this->now);
    }

    public function testABlankRejectReasonIsNoneAndALongOneIsRefused(): void
    {
        $suggestion = $this->suggestion(1);
        $suggestion->reject(Id::generate(), '   ', $this->now);
        self::assertNull($suggestion->rejectReason());

        $this->expectException(\InvalidArgumentException::class);
        $this->suggestion(1)->reject(Id::generate(), str_repeat('a', 501), $this->now);
    }

    public function testASupersededSuggestionNamesItsReplacementAndCannotBeDecided(): void
    {
        $suggestion = $this->suggestion(1);
        $replacement = Id::generate();

        $suggestion->supersede($replacement, $this->now);

        self::assertSame([SuggestionState::Superseded, $replacement, null], [$suggestion->state(), $suggestion->supersededBy(), $suggestion->decidedBy()]);
        $this->expectException(\LogicException::class);
        $suggestion->reject(Id::generate(), null, $this->now);
    }

    public function testItIsNotSupersededByItself(): void
    {
        $suggestion = $this->suggestion(1);

        $this->expectException(\InvalidArgumentException::class);
        $suggestion->supersede($suggestion->id(), $this->now);
    }

    public function testAnEditKeepsTheProposedInput(): void
    {
        $suggestion = $this->suggestion(1);

        $suggestion->editStep(1, ['text' => 'Edited']);

        $step = $suggestion->step(1);
        self::assertSame(['text' => 'Step 1'], $step->proposedInput());
        self::assertSame(['text' => 'Edited'], $step->finalInput());
        self::assertSame(['text' => 'Edited'], $step->input());
    }

    public function testItIsAcceptedOnceEveryTickedStepIsDone(): void
    {
        $suggestion = $this->suggestion(3);
        $userId = Id::generate();
        $suggestion->unselectStep(3);
        $first = Id::generate();

        $suggestion->step(1)->done('task', $first, $this->now);
        self::assertFalse($suggestion->acceptIfComplete($userId, $this->now), 'Step 2 is still pending.');
        $suggestion->step(2)->fail('invalid_input', 'title', $this->now);
        self::assertFalse($suggestion->acceptIfComplete($userId, $this->now), 'Step 2 failed.');
        self::assertSame([2], array_map(static fn (SuggestionStep $step): int => $step->position(), $suggestion->stepsToRun()), 'A retry runs only the failed step.');
        self::assertSame(['reason' => 'invalid_input', 'field' => 'title'], $suggestion->step(2)->refusal());

        $suggestion->step(2)->done('activity', Id::generate(), $this->now);

        self::assertTrue($suggestion->acceptIfComplete($userId, $this->now));
        self::assertSame([SuggestionState::Accepted, $userId], [$suggestion->state(), $suggestion->decidedBy()]);
        self::assertSame([StepState::Done, StepState::Done, StepState::Skipped], array_map(static fn (SuggestionStep $step): StepState => $step->state(), $suggestion->steps()));
        self::assertFalse($suggestion->step(3)->isSelected());
        self::assertSame($first, $suggestion->doneRecords()[1]);
        self::assertArrayNotHasKey(3, $suggestion->doneRecords());
        self::assertNull($suggestion->step(2)->refusal());
        self::assertFalse($suggestion->acceptIfComplete($userId, $this->now), 'It is accepted once.');
    }

    public function testADoneStepStoresTheInputItRanWithAndNeverRunsAgain(): void
    {
        $suggestion = $this->suggestion(1);
        $step = $suggestion->step(1);

        $step->done('task', Id::generate(), $this->now);

        self::assertSame(['text' => 'Step 1'], $step->finalInput());
        self::assertFalse($step->isRunnable());
        self::assertSame('UTC', $step->ranAt()?->getTimezone()->getName());
        foreach ([fn () => $step->done('task', Id::generate(), $this->now), fn () => $step->fail('internal_error', null, $this->now), $step->skip(...), fn () => $step->edit(['text' => 'Again'])] as $again) {
            try {
                $again();
                self::fail('Expected a refusal.');
            } catch (\LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testASuggestionWithEveryStepUntickedIsNotAccepted(): void
    {
        $suggestion = $this->suggestion(1);
        $suggestion->unselectStep(1);

        self::assertFalse($suggestion->acceptIfComplete(Id::generate(), $this->now));
        self::assertTrue($suggestion->isOpen());
    }

    public function testADecidedSuggestionTakesNoEdits(): void
    {
        $suggestion = $this->suggestion(1);
        $suggestion->reject(Id::generate(), null, $this->now);

        $this->expectException(\LogicException::class);
        $suggestion->editStep(1, ['text' => 'Edited']);
    }

    public function testAStepNamesItsRecordAndItsActionByKeys(): void
    {
        $step = $this->suggestion(1)->step(1);
        foreach ([fn () => $step->done('Task!', Id::generate(), $this->now), fn () => $step->done('task', 'not-an-id', $this->now), fn () => $step->fail('Not a code', null, $this->now), fn () => $step->edit(['a', 'b'])] as $wrong) {
            try {
                $wrong();
                self::fail('Expected a refusal.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(\OutOfRangeException::class);
        $this->suggestion(1)->step(2);
    }

    public function testTheTokenThatProposedItIsKnown(): void
    {
        $token = Id::generate();
        $suggestion = $this->suggestion(1, token: $token);

        self::assertTrue($suggestion->isProposedByToken($token));
        self::assertFalse($suggestion->isProposedByToken(Id::generate()));
        self::assertFalse($suggestion->isProposedByToken(null));
    }

    public function testTheTextsAreCountedInCharactersAtTheirLimits(): void
    {
        self::assertSame(2000, mb_strlen($this->suggestion(1, str_repeat('é', 2000))->rationale()));
        self::assertSame(1000, mb_strlen($this->suggestion(1, quote: str_repeat('é', 1000))->quote()));
        self::assertSame(2000, mb_strlen($this->suggestion(1, str_repeat('a', 2000))->rationale()));
        self::assertSame(1000, mb_strlen($this->suggestion(1, quote: str_repeat('a', 1000))->quote()));
        self::assertCount(10, $this->suggestion(10)->steps());
        $this->assertRefused(fn (): Suggestion => $this->suggestion(1, str_repeat('é', 2001)), 'A rationale has 1 to 2,000 characters.');
        $this->assertRefused(fn (): Suggestion => $this->suggestion(1, quote: str_repeat('é', 1001)), 'A quote has 1 to 1,000 characters.');
        $this->assertRefused(fn (): Suggestion => $this->suggestion(11), 'A suggestion has 1 to 10 steps.');
    }

    public function testItNeedsUuidIdsForItselfItsProposerAndItsToken(): void
    {
        $item = $this->suggestion(1)->item();
        foreach ([['bad', Id::generate(), Id::generate()], [Id::generate(), 'bad', Id::generate()], [Id::generate(), Id::generate(), 'bad']] as [$id, $user, $token]) {
            $this->assertRefused(fn (): Suggestion => new Suggestion($id, $item, [['operation' => 'inbox.actions.fixture_note', 'input' => []]], 'Why', 'Quote', 0, $user, $token, 'Agent', $this->now), 'A suggestion, its proposer and its token have UUIDv7 ids.');
        }
    }

    public function testTheClientNameIsTrimmedAndNeverBlank(): void
    {
        self::assertSame('Mail agent', $this->suggestion(1, client: "  Mail agent \n")->proposedByClient());
        self::assertSame(str_repeat('M', 100), $this->suggestion(1, client: '  '.str_repeat('M', 101))->proposedByClient());
        $this->assertRefused(fn (): Suggestion => $this->suggestion(1, client: "  \t "), 'A suggestion names the client that proposed it.');
    }

    public function testARejectReasonIsCutByCharactersAfterTheTrim(): void
    {
        foreach ([str_repeat('é', 500), '  '.str_repeat('a', 500).'  '] as $reason) {
            $suggestion = $this->suggestion(1);
            $suggestion->reject(Id::generate(), $reason, $this->now);
            self::assertSame(trim($reason), $suggestion->rejectReason());
        }
        $this->assertRefused(fn () => $this->suggestion(1)->reject(Id::generate(), str_repeat('é', 501), $this->now), 'A reject reason has at most 500 characters.');
    }

    public function testADecidedSuggestionTakesNoSupersedeNoUntickAndNoEdit(): void
    {
        foreach ([
            fn (Suggestion $suggestion) => $suggestion->supersede(Id::generate(), $this->now),
            fn (Suggestion $suggestion) => $suggestion->unselectStep(1),
            fn (Suggestion $suggestion) => $suggestion->editStep(1, ['text' => 'Edited']),
            fn (Suggestion $suggestion) => $suggestion->reject(Id::generate(), null, $this->now),
        ] as $change) {
            $suggestion = $this->suggestion(2);
            $suggestion->reject(Id::generate(), null, $this->now);
            try {
                $change($suggestion);
                self::fail('Expected a refusal.');
            } catch (\LogicException $refused) {
                self::assertSame('The suggestion is rejected already.', $refused->getMessage());
            }
            self::assertTrue($suggestion->step(1)->isSelected());
            self::assertNull($suggestion->step(1)->finalInput());
        }
    }

    public function testTheRecordsOfTheDoneStepsAreAllListedByPosition(): void
    {
        $suggestion = $this->suggestion(3);
        $first = Id::generate();
        $second = Id::generate();
        $suggestion->step(1)->done('task', $first, $this->now);
        $suggestion->step(3)->fail('invalid_input', null, $this->now);
        $suggestion->step(2)->done('activity', $second, $this->now);

        self::assertSame([1 => $first, 2 => $second], $suggestion->doneRecords());
        self::assertSame([3], array_map(static fn (SuggestionStep $step): int => $step->position(), $suggestion->stepsToRun()));
    }

    public function testADoneStepKeepsTheInputItWasGivenToRunWith(): void
    {
        $step = $this->suggestion(1)->step(1);
        $step->edit(['text' => 'Edited']);

        $step->done('task', Id::generate(), $this->now, ['text' => 'As it ran', 'task_id' => ['$step' => 1, 'field' => 'id']]);

        self::assertSame(['text' => 'As it ran', 'task_id' => ['$step' => 1, 'field' => 'id']], $step->finalInput());
        self::assertSame(['text' => 'Step 1'], $step->proposedInput());
    }

    public function testAStepIsAtPositionOneToTenWithAnOperationNameAndObjectInput(): void
    {
        $suggestion = $this->suggestion(1);
        foreach ([1, 10] as $position) {
            self::assertSame($position, new SuggestionStep(Id::generate(), $suggestion, $position, 'inbox.actions.a-b_c.1', [])->position());
        }
        self::assertSame(str_repeat('a', 100), new SuggestionStep(Id::generate(), $suggestion, 1, str_repeat('a', 100), [])->operation());
        $refused = [
            [0, 'inbox.actions.fixture_note'], [11, 'inbox.actions.fixture_note'], [-5, 'inbox.actions.fixture_note'],
            [1, 'Task'], [1, '!task'], [1, 'task!'], [1, 'a b'], [1, ''], [1, str_repeat('a', 101)],
        ];
        foreach ($refused as [$position, $operation]) {
            $this->assertRefused(static fn (): SuggestionStep => new SuggestionStep(Id::generate(), $suggestion, $position, $operation, []));
        }
        $this->assertRefused(static fn (): SuggestionStep => new SuggestionStep('bad', $suggestion, 1, 'inbox.actions.fixture_note', []), 'A suggestion step has a UUIDv7 id.');
        $this->assertRefused(static fn (): SuggestionStep => new SuggestionStep(Id::generate(), $suggestion, 1, 'inbox.actions.fixture_note', ['a', 'b']), 'The input of a step is an object of its fields.');
    }

    public function testARecordKindAndARefusalReasonAreCodes(): void
    {
        foreach (['task', 'time_entry', 'a'.str_repeat('b', 39)] as $kind) {
            $step = $this->suggestion(1)->step(1);
            $step->done($kind, Id::generate(), $this->now);
            self::assertSame($kind, $step->recordKind());
        }
        foreach (['Task', 'task!', '!task', '1task', 't-k', '', 'a'.str_repeat('b', 40)] as $kind) {
            $this->assertRefused(fn () => $this->suggestion(1)->step(1)->done($kind, Id::generate(), $this->now));
        }
        foreach (['invalid_input', 'a'.str_repeat('b', 63)] as $reason) {
            $step = $this->suggestion(1)->step(1);
            $step->fail($reason, null, $this->now);
            self::assertSame($reason, $step->refusal()['reason'] ?? null);
        }
        foreach (['Invalid', 'invalid-input', 'invalid input', '!invalid', '', 'a'.str_repeat('b', 64)] as $reason) {
            $this->assertRefused(fn () => $this->suggestion(1)->step(1)->fail($reason, null, $this->now));
        }
    }

    private function assertRefused(\Closure $work, ?string $message = null): void
    {
        try {
            $work();
        } catch (\InvalidArgumentException $refused) {
            if (null !== $message) {
                self::assertSame($message, $refused->getMessage());
            }
            $this->addToAssertionCount(1);

            return;
        }
        self::fail('Expected a refusal.');
    }

    private function suggestion(int $steps, string $rationale = 'The client asks for it.', string $quote = 'please find', string $client = 'Mail agent', ?string $token = null): Suggestion
    {
        $itemId = Id::generate();
        $item = new InboxItem($itemId, Id::generate(), Id::generate(), $this->now, false, null, ThreadKey::of([], null, 'offer-1@client-a.example.com', $itemId), null);

        return new Suggestion(
            Id::generate(),
            $item,
            array_map(static fn (int $position): array => ['operation' => 'inbox.actions.fixture_note', 'input' => ['text' => 'Step '.$position]], 0 === $steps ? [] : range(1, $steps)),
            $rationale,
            $quote,
            7,
            Id::generate(),
            $token ?? Id::generate(),
            $client,
            $this->now,
        );
    }
}
