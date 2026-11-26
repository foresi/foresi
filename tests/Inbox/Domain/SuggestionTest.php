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
        self::assertSame([1, 2, 3], array_map(static fn (\Foresi\Inbox\Domain\SuggestionStep $step): int => $step->position(), $suggestion->steps()));
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
        self::assertSame([2], array_map(static fn (\Foresi\Inbox\Domain\SuggestionStep $step): int => $step->position(), $suggestion->stepsToRun()), 'A retry runs only the failed step.');
        self::assertSame(['reason' => 'invalid_input', 'field' => 'title'], $suggestion->step(2)->refusal());

        $suggestion->step(2)->done('activity', Id::generate(), $this->now);

        self::assertTrue($suggestion->acceptIfComplete($userId, $this->now));
        self::assertSame([SuggestionState::Accepted, $userId], [$suggestion->state(), $suggestion->decidedBy()]);
        self::assertSame([StepState::Done, StepState::Done, StepState::Skipped], array_map(static fn (\Foresi\Inbox\Domain\SuggestionStep $step): StepState => $step->state(), $suggestion->steps()));
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
