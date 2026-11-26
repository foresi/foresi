<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Application;

use Foresi\Inbox\Contract\Event\InboxSuggestionProposed;
use Foresi\Inbox\Contract\Event\InboxSuggestionSuperseded;
use Foresi\Inbox\Domain\InboxItem;
use Foresi\Inbox\Domain\ItemState;
use Foresi\Inbox\Domain\Suggestion;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Shared\Contract\Event\EventRecorder;
use Foresi\Shared\Domain\Id;

/**
 * An agent proposes what to do with one of the person's new inbox items: one
 * or more suggestions, each of steps that are actions the item offers
 * (available_actions) with their input, a rationale, and a quote of the mail
 * it rests on. Nothing runs: the person accepts, edits or rejects each later.
 *
 * The whole call is checked first and stored only when every suggestion
 * passes: each step's operation is an action that applies to the item, its
 * input fits the action (StepInputCheck: leaving out inbox_item_id and
 * mark_done, references only to earlier steps), the quote is an exact span of
 * the item's text (QuoteCheck), at most 10 suggestions of at most 10 steps.
 * A suggestion may name open suggestions of the same item that it replaces;
 * only ones the same token proposed, which become superseded by it. They are
 * locked after the item and checked again once locked.
 *
 * A refusal names its field by its path in the call, such as
 * suggestions.0.steps.1.input.projectId (input.project_id on the wire), and never quotes the mail or the input.
 */
final readonly class ProposeSuggestions
{
    public const int MAX_SUGGESTIONS = 10;
    private const array SUGGESTION_KEYS = ['steps', 'rationale', 'quote', 'replaces'];
    private const array STEP_KEYS = ['operation', 'input'];

    public function __construct(
        private InboxAccess $access,
        private ItemReads $reads,
        private ItemStore $items,
        private SuggestionStore $store,
        private InboxActions $actions,
        private InboxTransaction $transaction,
        private EventRecorder $events,
        private InstanceClock $clock,
    ) {
    }

    /**
     * @param list<mixed> $suggestions as sent: objects of steps (objects of operation and input), rationale, quote and replaces
     *
     * @return list<Suggestion> the stored suggestions, in the order sent
     *
     * @throws InboxRefused not_found, item_trashed, item_closed, invalid_input, too_many, action_not_applicable, invalid_reference, quote_not_in_email, not_open, not_yours, forbidden
     */
    public function propose(string $itemId, array $suggestions, ProposingClient $by): array
    {
        $userId = $this->access->me();

        return $this->transaction->run(function () use ($itemId, $suggestions, $by, $userId): array {
            $item = $this->access->ownOrTrashed($itemId);
            if ($item->isTrashed()) {
                throw InboxRefused::itemTrashed();
            }
            // One proposal after the other on an item, so two replacing the same suggestion do not both win.
            $this->items->lock($item);
            if (ItemState::New !== $item->state()) {
                throw InboxRefused::itemClosed();
            }
            if ([] === $suggestions) {
                throw InboxRefused::invalidInput('suggestions', 'Propose at least one suggestion.');
            }
            if (\count($suggestions) > self::MAX_SUGGESTIONS) {
                throw InboxRefused::tooMany('suggestions', self::MAX_SUGGESTIONS);
            }
            $this->lockReplaced($item, $suggestions);
            $detail = $this->reads->detail($item, true);
            $offered = [];
            foreach ($this->actions->available($detail->view(true)) as $action) {
                $offered[$action->name()] = $action;
            }

            $checked = [];
            $replaced = [];
            foreach ($suggestions as $index => $suggestion) {
                $checked[] = $this->checked($item, $detail->text, $offered, $suggestion, 'suggestions.'.$index, $by, $replaced);
            }

            $now = $this->now();
            $stored = [];
            foreach ($checked as [$steps, $rationale, $quote, $offset, $replaces]) {
                $suggestion = new Suggestion(Id::generate(), $item, $steps, $rationale, $quote, $offset, $userId, $by->tokenId, $by->clientName, $now);
                $this->store->add($suggestion);
                $this->events->record(new InboxSuggestionProposed($suggestion->id(), $item->id()));
                foreach ($replaces as $old) {
                    $old->supersede($suggestion->id(), $now);
                    $this->events->record(new InboxSuggestionSuperseded($old->id(), $item->id()));
                }
                $stored[] = $suggestion;
            }

            return $stored;
        });
    }

    /**
     * One suggestion of the call, checked.
     *
     * @param array<string, DeclaredAction> $offered  the actions of the item, by operation
     * @param array<string, true>           $replaced the suggestions earlier ones of the call replace, by id
     *
     * @return array{list<array{operation: string, input: array<mixed>}>, string, string, int, list<Suggestion>}
     *
     * @throws InboxRefused
     */
    private function checked(InboxItem $item, ?string $text, array $offered, mixed $suggestion, string $path, ProposingClient $by, array &$replaced): array
    {
        $suggestion = $this->object($suggestion, $path, self::SUGGESTION_KEYS, ['steps', 'rationale', 'quote']);
        $rationale = $suggestion['rationale'];
        if (!\is_string($rationale) || '' === trim($rationale) || mb_strlen($rationale) > Suggestion::MAX_RATIONALE || str_contains($rationale, "\0")) {
            throw InboxRefused::invalidInput($path.'.rationale', 'A rationale is a text of 1 to 2,000 characters.');
        }
        $quote = $suggestion['quote'];
        // A quote of blanks only would be found in nearly every mail: it shows nothing of the source.
        if (!\is_string($quote) || '' === trim($quote) || mb_strlen($quote) > Suggestion::MAX_QUOTE || str_contains($quote, "\0")) {
            throw InboxRefused::invalidInput($path.'.quote', 'A quote is a text of 1 to 1,000 characters, not only blanks.');
        }
        $offset = QuoteCheck::offset($text, $quote);
        if (null === $offset) {
            throw InboxRefused::quoteNotInEmail($path.'.quote');
        }

        return [$this->steps($item, $offered, $suggestion['steps'], $path.'.steps'), $rationale, $quote, $offset, $this->replaces($item, $suggestion['replaces'] ?? [], $path.'.replaces', $by, $replaced)];
    }

    /**
     * @param array<string, DeclaredAction> $offered
     *
     * @return list<array{operation: string, input: array<mixed>}>
     *
     * @throws InboxRefused
     */
    private function steps(InboxItem $item, array $offered, mixed $steps, string $path): array
    {
        if (!\is_array($steps) || !array_is_list($steps) || [] === $steps) {
            throw InboxRefused::invalidInput($path, 'A suggestion has a list of 1 to 10 steps.');
        }
        if (\count($steps) > Suggestion::MAX_STEPS) {
            throw InboxRefused::tooMany($path, Suggestion::MAX_STEPS);
        }
        $checked = [];
        foreach ($steps as $index => $step) {
            $stepPath = $path.'.'.$index;
            $step = $this->object($step, $stepPath, self::STEP_KEYS, self::STEP_KEYS);
            $operation = $step['operation'];
            if (!\is_string($operation)) {
                throw InboxRefused::invalidInput($stepPath.'.operation', 'A step names its action by the operation, as available_actions lists it.');
            }
            $action = $offered[$operation] ?? throw InboxRefused::stepNotApplicable($stepPath.'.operation');
            $input = $step['input'];
            if (!\is_array($input)) {
                throw InboxRefused::invalidInput($stepPath.'.input', 'The input of a step is an object of the action\'s fields.');
            }
            StepInputCheck::check($action, $item->id(), $input, $index + 1, $stepPath.'.input');
            $checked[] = ['operation' => $operation, 'input' => $input];
        }

        return $checked;
    }

    /**
     * The open suggestions of the same item and token that one replaces.
     *
     * @param array<string, true> $replaced
     *
     * @return list<Suggestion>
     *
     * @throws InboxRefused
     */
    private function replaces(InboxItem $item, mixed $ids, string $path, ProposingClient $by, array &$replaced): array
    {
        if (!\is_array($ids) || !array_is_list($ids)) {
            throw InboxRefused::invalidInput($path, 'The suggestions it replaces are a list of their ids.');
        }
        $old = [];
        foreach ($ids as $index => $id) {
            $field = $path.'.'.$index;
            $suggestion = \is_string($id) ? $this->store->find($id) : null;
            if (!$suggestion instanceof Suggestion || $suggestion->itemId() !== $item->id()) {
                throw InboxRefused::suggestionNotFound($field);
            }
            if (!$suggestion->isProposedByToken($by->tokenId)) {
                throw InboxRefused::notYours($field);
            }
            if (!$suggestion->isOpen() || isset($replaced[$suggestion->id()])) {
                throw InboxRefused::suggestionNotOpen($field);
            }
            $replaced[$suggestion->id()] = true;
            $old[] = $suggestion;
        }

        return $old;
    }

    /**
     * Locks the open suggestions of the item the call names in replaces, by id, after the item
     * (the order every decision locks in), so that one accepted or rejected meanwhile is seen as
     * such before it is superseded. What is malformed is refused later, in its place.
     *
     * @param list<mixed> $suggestions
     */
    private function lockReplaced(InboxItem $item, array $suggestions): void
    {
        $ids = [];
        foreach ($suggestions as $suggestion) {
            $replaces = \is_array($suggestion) ? ($suggestion['replaces'] ?? null) : null;
            foreach (\is_array($replaces) ? $replaces : [] as $id) {
                if (\is_string($id)) {
                    $ids[$id] = $id;
                }
            }
        }
        sort($ids);
        foreach ($ids as $id) {
            $old = $this->store->find($id);
            if ($old instanceof Suggestion && $old->itemId() === $item->id()) {
                $this->store->lockForUpdate($old);
            }
        }
    }

    /**
     * @param list<string> $known
     * @param list<string> $required
     *
     * @return array<string, mixed>
     *
     * @throws InboxRefused
     */
    private function object(mixed $value, string $path, array $known, array $required): array
    {
        if (!\is_array($value) || ([] !== $value && array_is_list($value))) {
            throw InboxRefused::invalidInput($path, 'This is an object.');
        }
        $object = [];
        foreach ($value as $key => $item) {
            if (!\is_string($key) || !\in_array($key, $known, true)) {
                throw InboxRefused::invalidInput($path, \sprintf('Its fields are %s; another one is not known.', implode(', ', $known)));
            }
            $object[$key] = $item;
        }
        foreach ($required as $key) {
            if (!\array_key_exists($key, $object)) {
                throw InboxRefused::invalidInput($path.'.'.$key, 'This field is required.');
            }
        }

        return $object;
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->clock()->now());
    }
}
