<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Application;

use Foresi\Inbox\Contract\Event\InboxItemDone;
use Foresi\Inbox\Contract\Event\InboxItemLinked;
use Foresi\Inbox\Contract\InboxAction;
use Foresi\Inbox\Contract\InboxActionInput;
use Foresi\Inbox\Domain\InboxItem;
use Foresi\Inbox\Domain\ItemOutput;
use Foresi\Inbox\Domain\ItemState;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Mail\Contract\MessageRetention;
use Foresi\Shared\Application\Api\ScopeGuard;
use Foresi\Shared\Contract\Event\EventRecorder;
use Foresi\Shared\Contract\Operation\Operation;
use Foresi\Shared\Contract\Operation\OperationObserver;
use Foresi\Shared\Contract\Operation\OperationOutput;
use Psr\Log\LoggerInterface;

/**
 * What an inbox action made becomes an output of its item, whichever module
 * declared the action (InboxActions):
 *
 * - before the handler, the action is checked: the token holds inbox:read too,
 *   the item is the person's own (another person's is not_found, as one that
 *   does not exist) and not in the trash (item_trashed), the action's
 *   condition applies (action_not_applicable), and the item has its e-mail
 *   activity or Activities takes one (email_activity_unavailable, while the
 *   type email is archived). A refusal here makes nothing;
 * - after it, in one transaction of the inbox: the made record, by the id in
 *   the answer's data, is added as an output under the action's kind, the
 *   item's one e-mail activity is made or extended with it and with the
 *   input's related records, InboxItemLinked is recorded, a discarded item
 *   keeps its mail, and a new item is marked done unless the input says not
 *   or suggestions on it wait for a decision (keptNew(): the answer says so).
 *
 * The record is committed by then and stays whatever happens here: a failure
 * to record it is logged by ids alone and answered false. The person links it
 * by hand where its kind can be linked (linksByHand()); a record of another
 * kind, such as a time entry, stays in its module without the item.
 *
 * The REST and MCP runner calls before() and after(), and an answer whose
 * record could not be added says so in its summary (NOT_LINKED, or NOT_ADDED
 * for a kind that cannot be linked by hand). A screen of a session calls
 * check() without the scope and record() itself.
 */
final readonly class RecordOutputs implements OperationObserver
{
    public const string READ_SCOPE = 'inbox:read';
    /** What the answer of an action adds when its record could not be added to the item. */
    public const string NOT_LINKED = 'The record was made but not linked to the inbox item; link it with foresi_link_inbox_item.';
    /** The same for a kind that cannot be linked by hand, such as a time entry: the record stays in its module. */
    public const string NOT_ADDED = 'The record was made but not added to the inbox item, and a record of this kind cannot be linked by hand; it stays as made, so do not run the action again.';
    /** What the answer adds when mark_done left the item new because suggestions on it wait for a decision. */
    public const string KEPT_NEW = 'The item stays new because suggestions on it wait for a decision; the last decision on them marks it done.';

    public function __construct(
        private InboxAccess $access,
        private ItemReads $reads,
        private ItemStore $store,
        private InboxActions $actions,
        private InboxTransaction $transaction,
        private EventRecorder $events,
        private InstanceClock $clock,
        private MessageRetention $retention,
        private EmailActivity $emailActivity,
        private ScopeGuard $scopes,
        private LoggerInterface $logger,
        private SuggestionStore $suggestions,
    ) {
    }

    /**
     * @throws InboxRefused insufficient_scope, not_found, item_trashed, action_not_applicable, email_activity_unavailable, forbidden
     */
    public function before(Operation $operation, object $input): void
    {
        $action = InboxActions::attributeOf($input::class);
        if ($action instanceof InboxAction && $input instanceof InboxActionInput) {
            $this->check($action, $input, true);
        }
    }

    /**
     * The handler's answer; one that says so in its summary when the record could not be added to the item.
     */
    public function after(Operation $operation, object $input, OperationOutput $output): OperationOutput
    {
        $action = InboxActions::attributeOf($input::class);
        if (!$action instanceof InboxAction || !$input instanceof InboxActionInput) {
            return $output;
        }
        $summary = $output->summary;
        if (!$this->record($action, $input, $output)) {
            $summary .= ' '.($this->linksByHand($action) ? self::NOT_LINKED : self::NOT_ADDED);
        } elseif ($this->keptNew($input)) {
            $summary .= ' '.self::KEPT_NEW;
        }

        return $summary === $output->summary ? $output : new OperationOutput($output->data, $summary, $output->location);
    }

    /**
     * Whether mark_done asked for the item to be done but it stays new, as suggestions on it
     * wait for a decision (the last decision marks it done); call it after record().
     */
    public function keptNew(InboxActionInput $input): bool
    {
        $itemId = $input->inboxItemId();
        if (null === $itemId || !$input->markDone()) {
            return false;
        }
        try {
            $item = $this->access->own($itemId);
        } catch (InboxRefused) {
            return false;
        }

        return ItemState::New === $item->state() && ($this->suggestions->openCounts([$itemId])[$itemId] ?? 0) > 0;
    }

    /**
     * Whether what the action makes can be linked to an item by hand (inbox.items.link) when
     * record() could not add it.
     */
    public function linksByHand(InboxAction $action): bool
    {
        return $this->emailActivity->linksByHand($action->kind);
    }

    /**
     * Whether the action may run on its item; nothing to check for an input
     * without an item. The handler of an action checks nothing of the item, so
     * a caller outside the runner, such as a screen of a session, must call
     * this before the handler, with $checkScope false, and answer not_found as
     * a 404; then record() after it.
     *
     * @param bool $checkScope whether the token must hold inbox:read, false for a session
     *
     * @throws InboxRefused insufficient_scope, not_found, item_trashed, action_not_applicable, email_activity_unavailable, forbidden
     */
    public function check(InboxAction $action, InboxActionInput $input, bool $checkScope): void
    {
        $itemId = $input->inboxItemId();
        if (null === $itemId) {
            return;
        }
        if ($checkScope && !$this->scopes->allows(self::READ_SCOPE)) {
            throw InboxRefused::insufficientScope(self::READ_SCOPE);
        }
        $item = $this->access->ownOrTrashed($itemId);
        if ($item->isTrashed()) {
            throw InboxRefused::itemTrashed();
        }
        if (null !== $action->appliesTo && !$this->actions->applies($action, $this->reads->detail($item, true)->view(true))) {
            throw InboxRefused::actionNotApplicable();
        }
        if (!$this->emailActivity->canLink($item)) {
            // record() would be refused by Activities and roll back: what the handler made would
            // stay outside the item, and a time entry or an activity could not be linked by hand.
            throw InboxRefused::emailActivityUnavailable();
        }
    }

    /**
     * Records what the action made on its item.
     *
     * @return bool false when it could not be recorded, which is logged; true when recorded or there is no item
     */
    public function record(InboxAction $action, InboxActionInput $input, OperationOutput $output): bool
    {
        $itemId = $input->inboxItemId();
        if (null === $itemId) {
            return true;
        }
        $recordId = $output->data['id'] ?? null;
        try {
            if (!\is_string($recordId)) {
                throw new \UnexpectedValueException('The answer of the action has no id.');
            }
            $userId = $this->access->me();
            $this->transaction->run(function () use ($action, $input, $itemId, $recordId, $userId): void {
                $item = $this->access->own($itemId);
                // One action after the other on an item, so that a second sees the e-mail activity of the first.
                $this->store->lock($item);
                $this->addOutput($item, $action->kind, $recordId, $userId);
                foreach ($input->relatedRecords() as $related) {
                    $this->emailActivity->link($item, $related->kind, $related->id);
                }
                // While suggestions on it wait for a decision, the last decision marks it done, not this action.
                if ($input->markDone() && ItemState::New === $item->state() && 0 === ($this->suggestions->openCounts([$item->id()])[$item->id()] ?? 0)
                    && $item->changeState(ItemState::Done, $userId, $this->now())) {
                    $this->events->record(new InboxItemDone($item->id()));
                }
            });

            return true;
        } catch (\Throwable $failure) {
            // Ids and the class of the failure only: its message may carry what was sent.
            $this->logger->error('The record of an inbox action could not be added to its item.', [
                'item_id' => $itemId,
                'kind' => $action->kind,
                'record_id' => \is_string($recordId) ? $recordId : null,
                'failure' => $failure::class,
            ]);

            return false;
        }
    }

    private function addOutput(InboxItem $item, string $kind, string $recordId, string $userId): void
    {
        foreach ($this->store->outputs($item) as $output) {
            if ($output->kind() === $kind && $output->recordId() === $recordId) {
                return;
            }
        }
        if (ItemState::Discarded === $item->state()) {
            // Something came of it now: the mail is kept.
            $this->retention->keep($item->mailMessageId());
        }
        $this->store->add(new ItemOutput($item, $kind, $recordId, $this->now(), $userId));
        $this->emailActivity->link($item, $kind, $recordId);
        $this->events->record(new InboxItemLinked($item->id(), $kind));
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->clock()->now());
    }
}
