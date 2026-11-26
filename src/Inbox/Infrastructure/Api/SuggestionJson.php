<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Infrastructure\Api;

use Foresi\Inbox\Application\Decision;
use Foresi\Inbox\Domain\Suggestion;
use Foresi\Inbox\Domain\SuggestionStep;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Shared\Infrastructure\Operation\OperationField;

/**
 * A suggestion as REST and MCP answer it, the schema InboxSuggestion: its
 * state, the agent's rationale and quote (where the quote starts in the body,
 * in characters), the client that proposed it, the decision, and each step
 * with its proposed and final input, whether it is ticked, its state, the
 * record it made and the reason it was refused. The rationale, quote and
 * inputs are an agent's text and the quote the mail's: MCP marks them as
 * data. Times are in the instance's zone.
 */
final readonly class SuggestionJson
{
    public function __construct(private InstanceClock $clock)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function of(Suggestion $suggestion): array
    {
        return [
            'id' => $suggestion->id(),
            'item_id' => $suggestion->itemId(),
            'state' => $suggestion->state()->value,
            'rationale' => $suggestion->rationale(),
            'quote' => $suggestion->quote(),
            'quote_offset' => $suggestion->quoteOffset(),
            'proposed_by' => ['client_name' => $suggestion->proposedByClient()],
            'proposed_at' => $this->time($suggestion->proposedAt()),
            'decided_at' => $this->time($suggestion->decidedAt()),
            'reject_reason' => $suggestion->rejectReason(),
            'superseded_by' => $suggestion->supersededBy(),
            'steps' => array_map($this->step(...), $suggestion->steps()),
        ];
    }

    /**
     * What came of deciding one suggestion: its state and its steps' states,
     * records and refusals, without the agent's text; refusal is why the
     * suggestion itself was not decided, null when its steps ran.
     *
     * @return array<string, mixed>
     */
    public function decision(Decision $decision): array
    {
        $suggestion = $decision->suggestion;

        return [
            'id' => $decision->suggestionId,
            'item_id' => $suggestion?->itemId(),
            'state' => $suggestion?->state()->value,
            'refusal' => null === $decision->refused ? null : ['reason' => $decision->refused, 'field' => null],
            'steps' => $suggestion instanceof Suggestion ? array_map(fn (SuggestionStep $step): array => [
                'position' => $step->position(),
                'state' => $step->state()->value,
                'record' => null !== $step->recordKind() && null !== $step->recordId() ? ['kind' => $step->recordKind(), 'id' => $step->recordId()] : null,
                'refusal' => $this->refusal($step),
            ], $suggestion->steps()) : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function step(SuggestionStep $step): array
    {
        $recordKind = $step->recordKind();
        $recordId = $step->recordId();
        $final = $step->finalInput();

        return [
            'position' => $step->position(),
            'operation' => $step->operation(),
            'proposed_input' => $this->object($step->proposedInput()),
            'final_input' => null === $final ? null : $this->object($final),
            'selected' => $step->isSelected(),
            'state' => $step->state()->value,
            'record' => null !== $recordKind && null !== $recordId ? ['kind' => $recordKind, 'id' => $recordId] : null,
            'refusal' => $this->refusal($step),
        ];
    }

    /**
     * An input as a JSON object, an empty one included.
     *
     * @param array<mixed> $input
     *
     * @return array<mixed>|\stdClass
     */
    private function object(array $input): array|\stdClass
    {
        return [] === $input ? new \stdClass() : $input;
    }

    /**
     * A step's refusal with its field as the client sends it: input.taskId is input.task_id, as
     * every refusal of a call names its field.
     *
     * @return array{reason: string, field: string|null}|null
     */
    private function refusal(SuggestionStep $step): ?array
    {
        $refusal = $step->refusal();

        return null === $refusal ? null : ['reason' => $refusal['reason'], 'field' => null === $refusal['field'] ? null : OperationField::wireName($refusal['field'])];
    }

    private function time(?\DateTimeImmutable $time): ?string
    {
        return $time?->setTimezone($this->clock->timezone())->format(\DATE_ATOM);
    }
}
