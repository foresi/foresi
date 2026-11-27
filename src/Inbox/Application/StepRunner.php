<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Application;

use Foresi\Audit\Application\AuditCorrelation;
use Foresi\Inbox\Contract\InboxActionInput;
use Foresi\Inbox\Domain\StepState;
use Foresi\Inbox\Domain\Suggestion;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Shared\Application\Api\ApiClient;
use Foresi\Shared\Application\Api\ScopeGuard;
use Foresi\Shared\Contract\Operation\OperationOutput;
use Foresi\Shared\Contract\Refused;
use Foresi\Shared\Infrastructure\Operation\InputMapper;
use Foresi\Shared\Infrastructure\Operation\OperationRunner;
use Psr\Log\LoggerInterface;

/**
 * Runs one step of a suggestion as the person accepting it: its action with
 * the step's input, the item and mark_done false added, and its references to
 * earlier steps replaced by the records they made (StepReferences).
 *
 * The step's identity is inbox:suggestion:<id>:step:<n> (key()), and the
 * step row is its idempotency: the step runs in one transaction that this
 * runner opens, with the item's row and then the suggestion's locked, and stores the record it
 * made and "done" in that same transaction. The record and "done" commit
 * together, so a done step never runs again, not under two accepts at once
 * nor after a crash. Every record made is audited under the suggestion's
 * correlation id (correlation()) and by the person, as the modules' own
 * transactions join this one and keep its correlation.
 *
 * With an API token the action runs through the OperationRunner, so the token
 * needs the action's own scope (insufficient_scope otherwise) and the inbox
 * observer checks and records it; in a session the inbox action screen's way:
 * the input mapped, checked without a scope, handled and recorded. What the
 * item could not record still counts as done: the record exists.
 *
 * A refusal rolls the whole step back and stores, in a transaction of its
 * own, the step failed with the refusal's reason and field only, never its
 * message, which could quote the input. A field is named by its path in the
 * step, such as input.projectId (input.project_id on the wire). Any other failure is stored as internal_error
 * and logged by ids and class.
 *
 * Call it outside a transaction: it must own the one the step runs in.
 */
final readonly class StepRunner
{
    public const string INTERNAL_ERROR = 'internal_error';
    /** The reason codes a step stores (SuggestionStep::fail()). */
    private const string REASON = '/^[a-z][a-z0-9_]{0,63}$/';

    public function __construct(
        private SuggestionStore $store,
        private ItemStore $items,
        private InboxAccess $access,
        private InboxActions $actions,
        private RecordOutputs $outputs,
        private OperationRunner $runner,
        private ApiClient $client,
        private ScopeGuard $scopes,
        private AuditCorrelation $correlation,
        private InboxTransaction $transaction,
        private InstanceClock $clock,
        private LoggerInterface $logger,
    ) {
    }

    /** The audit correlation id of everything a suggestion's steps make. */
    public static function correlation(string $suggestionId): string
    {
        return 'inbox:suggestion:'.$suggestionId;
    }

    /** The identity of one step, for its logs. */
    private function key(string $suggestionId, int $position): string
    {
        return self::correlation($suggestionId).':step:'.$position;
    }

    /**
     * Runs the step when it is to run (ticked, pending or failed, its suggestion open).
     *
     * @return StepState the state of the step afterwards: done, failed, or as it was when it was not to run
     *
     * @throws InboxRefused not_found when the suggestion is not the person's or gone
     */
    public function run(string $suggestionId, int $position): StepState
    {
        return $this->correlation->within(self::correlation($suggestionId), function () use ($suggestionId, $position): StepState {
            try {
                $state = $this->transaction->run(fn (): ?StepState => $this->runLocked($suggestionId, $position));
            } catch (Refused $refused) {
                $field = $refused->field();
                $this->fail($suggestionId, $position, $refused->reason(), null === $field ? null : $this->inInput($field));

                return StepState::Failed;
            } catch (\Throwable $failure) {
                // Ids and the class only: a message may carry what was sent.
                $this->logger->error('A step of an inbox suggestion failed.', [
                    'step' => $this->key($suggestionId, $position),
                    'failure' => $failure::class,
                ]);
                $this->fail($suggestionId, $position, self::INTERNAL_ERROR, null);

                return StepState::Failed;
            }

            return $state ?? throw InboxRefused::notFound();
        });
    }

    /**
     * @return StepState|null null when the suggestion is not the person's or gone
     *
     * @throws Refused
     */
    private function runLocked(string $suggestionId, int $position): ?StepState
    {
        $suggestion = $this->locked($suggestionId);
        if (!$suggestion instanceof Suggestion) {
            return null;
        }
        $step = $suggestion->step($position);
        if (!$suggestion->isOpen() || !$step->isRunnable()) {
            return $step->state();
        }
        $action = $this->actions->get($step->operation()) ?? throw InboxRefused::stepNotApplicable('operation');
        $scope = $action->definition->operation->scope;
        $sent = StepReferences::resolve($step->input(), $suggestion->doneRecords())
            + ['inbox_item_id' => $suggestion->itemId(), 'mark_done' => false];
        $token = null !== $this->client->tokenId();
        if ($token && !$this->scopes->allows($scope)) {
            throw InboxRefused::insufficientScope($scope);
        }
        $output = $token ? $this->runner->run($action->definition, $sent) : $this->inSession($action, $sent);
        $recordId = $output->data['id'] ?? null;
        if (!\is_string($recordId)) {
            throw new \UnexpectedValueException('The answer of the action has no id.');
        }
        $step->done($action->action->kind, $recordId, $this->now());

        return StepState::Done;
    }

    /**
     * The inbox action screen's way, without a token's scope.
     *
     * @param array<mixed> $sent
     *
     * @throws Refused
     */
    private function inSession(DeclaredAction $action, array $sent): OperationOutput
    {
        $input = InputMapper::map($action->definition, $sent);
        if (!$input instanceof InboxActionInput) {
            throw new \LogicException(\sprintf('The input of %s is no inbox action input.', $action->name()));
        }
        $this->outputs->check($action->action, $input, false);
        $output = $action->definition->handler->handle($input);
        $this->outputs->record($action->action, $input, $output);

        return $output;
    }

    /**
     * Stores the step failed, in a transaction of its own after the step's rolled back.
     */
    private function fail(string $suggestionId, int $position, string $reason, ?string $field): void
    {
        $this->transaction->run(function () use ($suggestionId, $position, $reason, $field): void {
            $suggestion = $this->locked($suggestionId);
            if ($suggestion instanceof Suggestion && $suggestion->isOpen() && $suggestion->step($position)->isRunnable()) {
                // A module's reason that is no code is stored as internal_error rather than failing here.
                $code = 1 === preg_match(self::REASON, $reason) ? $reason : self::INTERNAL_ERROR;
                $suggestion->step($position)->fail($code, $field, $this->now());
            }
        });
    }

    /**
     * The person's suggestion, its item's row and its own locked for the open transaction; null when it is not theirs or gone.
     *
     * @throws InboxRefused forbidden
     */
    private function locked(string $suggestionId): ?Suggestion
    {
        $suggestion = $this->store->find($suggestionId);
        if (!$suggestion instanceof Suggestion) {
            return null;
        }
        try {
            $item = $this->access->ownOrTrashed($suggestion->itemId());
        } catch (InboxRefused $refused) {
            if ('not_found' === $refused->reason()) {
                return null;
            }
            throw $refused;
        }
        // The item first, then the suggestion: the order every decision and proposal locks in.
        $this->items->lock($item);
        $this->store->lockForUpdate($suggestion);

        return $suggestion;
    }

    /**
     * A refusal's field as a path in the step: projectId is input.projectId, input.taskId stays.
     */
    private function inInput(string $field): string
    {
        return str_starts_with($field, 'input.') || 'operation' === $field ? $field : 'input.'.$field;
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->clock()->now());
    }
}
