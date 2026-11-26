<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Application;

use Foresi\Audit\Application\AuditCorrelation;
use Foresi\Inbox\Contract\Event\InboxItemDone;
use Foresi\Inbox\Contract\Event\InboxSuggestionAccepted;
use Foresi\Inbox\Contract\Event\InboxSuggestionRejected;
use Foresi\Inbox\Domain\ItemState;
use Foresi\Inbox\Domain\StepState;
use Foresi\Inbox\Domain\Suggestion;
use Foresi\Inbox\Domain\SuggestionState;
use Foresi\Inbox\Domain\SuggestionStep;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Shared\Contract\Event\EventRecorder;
use Foresi\Shared\Contract\Refused;
use Psr\Log\LoggerInterface;

/**
 * The owner of an inbox item decides on the suggestions agents proposed on it.
 *
 * Accept takes one or more suggestions, optionally with edits of their steps'
 * inputs and steps unticked. The whole call is checked first: an edit is
 * checked as a proposed input is (StepInputCheck), and an invalid edit or a
 * malformed field refuses the call before anything runs. Then each suggestion
 * is decided on its own, so one that fails or is refused (not_found, not_open,
 * item_trashed) never stops or undoes another: its edits are stored as the
 * steps' final input (the proposed input stays), unticked steps are skipped,
 * and its steps run in their order, each in its own transaction (StepRunner).
 * The first step that fails stops the suggestion: it stays proposed with that
 * step failed and the rest pending, and accepting it again runs only the
 * failed and pending steps, never a done one. A suggestion whose ticked steps
 * are all done is accepted.
 *
 * Reject takes one open suggestion with an optional reason.
 *
 * After a decision that leaves the item without an open suggestion, a new
 * item with an accepted one is marked done: the last decision is what marks
 * it, as running an action does.
 */
final readonly class DecideSuggestions
{
    public const int MAX_SUGGESTIONS = 10;

    public function __construct(
        private InboxAccess $access,
        private ItemStore $items,
        private SuggestionStore $store,
        private InboxActions $actions,
        private StepRunner $steps,
        private InboxTransaction $transaction,
        private AuditCorrelation $correlation,
        private EventRecorder $events,
        private InstanceClock $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<mixed>       $suggestionIds the suggestions, 1 to 10, in the order to decide them
     * @param array<mixed>|null $edits         by suggestion id, by step position: the input to run the step with
     * @param array<mixed>|null $unselected    by suggestion id: the positions of the steps not to run
     *
     * @return list<Decision> one per suggestion, in the order named
     *
     * @throws InboxRefused invalid_input, too_many, invalid_reference, action_not_applicable for the call; forbidden
     */
    public function accept(array $suggestionIds, ?array $edits = null, ?array $unselected = null): array
    {
        $userId = $this->access->me();
        $ids = $this->ids($suggestionIds);
        $edits = $this->byId($edits ?? [], $ids, 'edits');
        $unselected = $this->byId($unselected ?? [], $ids, 'unselected');

        $plans = [];
        foreach ($ids as $id) {
            $suggestion = $this->mine($id);
            if (!$suggestion instanceof Suggestion) {
                $plans[$id] = 'not_found';
                continue;
            }
            $plans[$id] = $this->refusalOf($suggestion) ?? $this->planned($id, $suggestion, $edits[$id] ?? [], $unselected[$id] ?? []);
        }

        $decisions = [];
        foreach ($plans as $id => $plan) {
            $decisions[] = \is_string($plan)
                ? new Decision($id, $this->mine($id), $plan)
                : $this->correlation->within(StepRunner::correlation($id), fn (): Decision => $this->decide($id, $userId, $plan[0], $plan[1]));
        }

        return $decisions;
    }

    /**
     * @param string|null $reason why, at most 500 characters; blank is none
     *
     * @throws InboxRefused not_found, not_open, item_trashed, invalid_input, forbidden
     */
    public function reject(string $suggestionId, ?string $reason): Suggestion
    {
        $userId = $this->access->me();
        if (null !== $reason && (mb_strlen(trim($reason)) > Suggestion::MAX_REJECT_REASON || str_contains($reason, "\0"))) {
            throw InboxRefused::invalidInput('reason', 'A reason has at most 500 characters.');
        }

        return $this->correlation->within(StepRunner::correlation($suggestionId), fn (): Suggestion => $this->transaction->run(function () use ($suggestionId, $reason, $userId): Suggestion {
            $suggestion = $this->mine($suggestionId) ?? throw InboxRefused::noSuggestion();
            if ($suggestion->item()->isTrashed()) {
                throw InboxRefused::itemTrashed();
            }
            $this->lock($suggestion);
            if (!$suggestion->isOpen()) {
                throw InboxRefused::suggestionClosed();
            }
            $suggestion->reject($userId, $reason, $this->now());
            $this->events->record(new InboxSuggestionRejected($suggestion->id(), $suggestion->itemId()));
            $this->closeIfDecided($suggestion->itemId(), $userId);

            return $suggestion;
        }));
    }

    /**
     * Runs the plan of one suggestion that passed the check of the call.
     *
     * @param array<int, array<mixed>> $edits      by position
     * @param list<int>                $unselected
     */
    private function decide(string $id, string $userId, array $edits, array $unselected): Decision
    {
        try {
            $planned = $this->transaction->run(function () use ($id, $edits, $unselected): string|array {
                $suggestion = $this->mine($id);
                if (!$suggestion instanceof Suggestion) {
                    return 'not_found';
                }
                $this->lock($suggestion);
                if (!$suggestion->isOpen()) {
                    // Decided since the call was checked.
                    return 'not_open';
                }
                foreach ($edits as $position => $input) {
                    if ($suggestion->step($position)->isRunnable()) {
                        $suggestion->editStep($position, $input);
                    }
                }
                foreach ($unselected as $position) {
                    if (!\in_array($suggestion->step($position)->state(), [StepState::Done, StepState::Skipped], true)) {
                        $suggestion->unselectStep($position);
                    }
                }

                return array_map(static fn (SuggestionStep $step): int => $step->position(), $suggestion->stepsToRun());
            });
            if (\is_string($planned)) {
                return new Decision($id, $this->mine($id), $planned);
            }
            foreach ($planned as $position) {
                if (StepState::Done !== $this->steps->run($id, $position)) {
                    break;
                }
            }
            $this->transaction->run(function () use ($id, $userId): void {
                $suggestion = $this->mine($id);
                if (!$suggestion instanceof Suggestion) {
                    return;
                }
                $this->lock($suggestion);
                if ($suggestion->acceptIfComplete($userId, $this->now())) {
                    $this->events->record(new InboxSuggestionAccepted($suggestion->id(), $suggestion->itemId()));
                    $this->closeIfDecided($suggestion->itemId(), $userId);
                }
            });
        } catch (Refused $refused) {
            return new Decision($id, $this->mine($id), $refused->reason());
        } catch (\Throwable $failure) {
            // One suggestion's failure stops none of the others; ids and the class only.
            $this->logger->error('An inbox suggestion could not be decided.', ['suggestion_id' => $id, 'failure' => $failure::class]);

            return new Decision($id, $this->mine($id), StepRunner::INTERNAL_ERROR);
        }

        return new Decision($id, $this->mine($id));
    }

    /**
     * Locks the suggestion's item, then the suggestion: the order every decision and proposal
     * locks in, so two of them on one item never wait on each other.
     */
    private function lock(Suggestion $suggestion): void
    {
        $this->items->lock($suggestion->item());
        $this->store->lockForUpdate($suggestion);
    }

    /**
     * Marks a new item done once no suggestion on it is open and one was accepted.
     */
    private function closeIfDecided(string $itemId, string $userId): void
    {
        $item = $this->access->ownOrTrashed($itemId);
        $this->items->lock($item);
        if (ItemState::New !== $item->state() || $item->isTrashed()) {
            return;
        }
        $suggestions = $this->store->forItem($item);
        if (array_any($suggestions, static fn (Suggestion $suggestion): bool => $suggestion->isOpen())
            || !array_any($suggestions, static fn (Suggestion $suggestion): bool => SuggestionState::Accepted === $suggestion->state())) {
            return;
        }
        if ($item->changeState(ItemState::Done, $userId, $this->now())) {
            $this->events->record(new InboxItemDone($item->id()));
        }
    }

    /**
     * Why the suggestion cannot be accepted at all, before the call runs; null when it can.
     */
    private function refusalOf(Suggestion $suggestion): ?string
    {
        return match (true) {
            $suggestion->item()->isTrashed() => 'item_trashed',
            !$suggestion->isOpen() => 'not_open',
            default => null,
        };
    }

    /**
     * The checked edits and unticked steps of an open suggestion of the person.
     *
     * @return array{array<int, array<mixed>>, list<int>}
     *
     * @throws InboxRefused
     */
    private function planned(string $id, Suggestion $suggestion, mixed $edits, mixed $unselected): array
    {
        $steps = \count($suggestion->steps());

        if (!\is_array($edits) || ([] !== $edits && array_is_list($edits))) {
            throw InboxRefused::invalidInput('edits.'.$id, 'The edits of a suggestion are an object of inputs by the position of their step.');
        }
        $edited = [];
        foreach ($edits as $key => $input) {
            $path = 'edits.'.$id.'.'.StepReferences::segment($key);
            $step = $this->stepAt($suggestion, $key, $steps, $path);
            if (!$step->isRunnable()) {
                throw InboxRefused::invalidInput($path, 'Only a step that is pending or failed can be edited: this one is done or unticked.');
            }
            if (!\is_array($input)) {
                throw InboxRefused::invalidInput($path, 'The input of a step is an object of the action\'s fields.');
            }
            $action = $this->actions->get($step->operation()) ?? throw InboxRefused::stepNotApplicable($path);
            StepInputCheck::check($action, $suggestion->itemId(), $input, $step->position(), $path);
            $edited[$step->position()] = $input;
        }

        if (!\is_array($unselected) || !array_is_list($unselected)) {
            throw InboxRefused::invalidInput('unselected.'.$id, 'The steps not to run are a list of their positions.');
        }
        $skipped = [];
        foreach ($unselected as $index => $key) {
            $path = 'unselected.'.$id.'.'.$index;
            $step = $this->stepAt($suggestion, $key, $steps, $path);
            if (StepState::Done === $step->state()) {
                throw InboxRefused::invalidInput($path, 'This step is done already.');
            }
            if (\array_key_exists($step->position(), $edited)) {
                throw InboxRefused::invalidInput($path, 'A step is either edited or not run, not both.');
            }
            $skipped[$step->position()] = $step->position();
        }

        $left = array_filter($suggestion->steps(), static fn (SuggestionStep $step): bool => StepState::Done === $step->state() || ($step->isRunnable() && !isset($skipped[$step->position()])));
        if ([] === $left) {
            throw InboxRefused::invalidInput('unselected.'.$id, 'Leave at least one step ticked, or reject the suggestion.');
        }

        return [$edited, array_values($skipped)];
    }

    /**
     * @throws InboxRefused invalid_input
     */
    private function stepAt(Suggestion $suggestion, mixed $key, int $steps, string $path): SuggestionStep
    {
        $position = \is_int($key) ? $key : (\is_string($key) && 1 === preg_match('/^[1-9][0-9]?$/D', $key) ? (int) $key : null);
        if (null === $position || $position < 1 || $position > $steps) {
            throw InboxRefused::invalidInput($path, \sprintf('A step is named by its position, 1 to %d.', $steps));
        }

        return $suggestion->step($position);
    }

    /**
     * @param list<mixed> $suggestionIds
     *
     * @return list<string> without repeats, in their order
     *
     * @throws InboxRefused
     */
    private function ids(array $suggestionIds): array
    {
        if ([] === $suggestionIds) {
            throw InboxRefused::invalidInput('suggestionIds', 'Name at least one suggestion.');
        }
        $ids = [];
        foreach ($suggestionIds as $index => $id) {
            if (!\is_string($id) || '' === $id) {
                throw InboxRefused::invalidInput('suggestionIds.'.$index, 'A suggestion is named by its id.');
            }
            $ids[$id] = $id;
        }
        if (\count($ids) > self::MAX_SUGGESTIONS) {
            throw InboxRefused::tooMany('suggestionIds', self::MAX_SUGGESTIONS);
        }

        return array_values($ids);
    }

    /**
     * An object by suggestion id, each one of the call's.
     *
     * @param array<mixed> $value
     * @param list<string> $ids
     *
     * @return array<string, mixed>
     *
     * @throws InboxRefused
     */
    private function byId(array $value, array $ids, string $field): array
    {
        if ([] !== $value && array_is_list($value)) {
            throw InboxRefused::invalidInput($field, 'This is an object by suggestion id.');
        }
        $by = [];
        foreach ($value as $id => $item) {
            if (!\in_array((string) $id, $ids, true)) {
                // Not echoed back: the key is whatever was sent.
                throw InboxRefused::invalidInput($field, 'A key names no suggestion of suggestion_ids.');
            }
            $by[(string) $id] = $item;
        }

        return $by;
    }

    /**
     * The person's suggestion, its item in the trash or not; null for one of another person or none.
     *
     * @throws InboxRefused forbidden
     */
    private function mine(string $id): ?Suggestion
    {
        $suggestion = $this->store->find($id);
        if (!$suggestion instanceof Suggestion) {
            return null;
        }
        try {
            $this->access->ownOrTrashed($suggestion->itemId());
        } catch (InboxRefused $refused) {
            if ('not_found' === $refused->reason()) {
                return null;
            }
            throw $refused;
        }

        return $suggestion;
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->clock()->now());
    }
}
