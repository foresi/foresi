<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Infrastructure\Api;

use Foresi\Party\Contract\PartyDirectory;
use Foresi\Party\Contract\PartyPersonSummary;
use Foresi\Sales\Application\DealDetail;
use Foresi\Sales\Application\DealLinkView;
use Foresi\Sales\Application\HourlyValue;
use Foresi\Sales\Application\LineProjects;
use Foresi\Sales\Application\LostReasonNames;
use Foresi\Sales\Application\SalesStore;
use Foresi\Sales\Application\StageNames;
use Foresi\Sales\Domain\Deal;
use Foresi\Sales\Domain\DealLine;
use Foresi\Sales\Domain\LostReason;
use Foresi\Sales\Domain\Stage;
use Foresi\Shared\Domain\Money;
use Foresi\Shared\Infrastructure\Api\JsonValues;

/**
 * Deals, stages and lost reasons as REST and MCP write them (schemas
 * SalesDeal, SalesDealSummary, SalesStage and SalesLostReason).
 */
final readonly class SalesJson
{
    public function __construct(
        private SalesStore $store,
        private PartyDirectory $parties,
        private StageNames $stageNames,
        private LostReasonNames $reasonNames,
        private LineProjects $projects,
    ) {
    }

    /**
     * One deal with its lines, value, links and contact persons.
     *
     * @return array<string, mixed>
     */
    public function deal(DealDetail $detail): array
    {
        $deal = $detail->deal;

        return [
            'id' => $deal->id(),
            'title' => $deal->title(),
            'description' => $deal->description(),
            'status' => $deal->status()->value,
            'client' => $this->client($deal),
            'owner_user_id' => $deal->ownerUserId(),
            'stage' => $this->stageReference($detail->stage),
            'won_at' => JsonValues::timestamp($deal->wonAt()),
            'lost_at' => JsonValues::timestamp($deal->lostAt()),
            'lost_reason' => $this->reasonReference($detail->lostReason),
            'lost_note' => $deal->lostNote(),
            'contacts' => array_map(static fn (PartyPersonSummary $person): array => ['id' => $person->id, 'name' => $person->displayName], $detail->contactPersons),
            'lines' => array_map($this->line(...), $detail->lines),
            'value' => [
                'one_off' => array_values(array_map(JsonValues::money(...), $detail->value->oneOff)),
                'monthly' => array_values(array_map(JsonValues::money(...), $detail->value->monthly)),
                'approximate' => $detail->value->approximate,
                'hourly' => array_map(static fn (HourlyValue $line): array => [
                    'rate' => JsonValues::money($line->rate),
                    'expected_hours' => $line->expectedHours,
                    'expected_amount' => $line->expectedAmount instanceof Money ? JsonValues::money($line->expectedAmount) : null,
                ], array_merge(...array_values($detail->value->hourly))),
            ],
            'links' => array_map(static fn (DealLinkView $view): array => [
                'id' => $view->link->id(),
                'type' => $view->link->type()->value,
                'direction' => $view->outgoing ? 'outgoing' : 'incoming',
                'deal' => ['id' => $view->other->id(), 'title' => $view->other->title(), 'status' => $view->other->status()->value],
            ], $detail->links),
            'trashed' => $deal->isTrashed(),
            'trashed_at' => JsonValues::timestamp($deal->trashedAt()),
            'created_at' => JsonValues::timestamp($deal->createdAt()),
            'updated_at' => JsonValues::timestamp($deal->updatedAt()),
        ];
    }

    /**
     * Deals as a list shows them, without their lines, links and contact persons.
     *
     * @param list<Deal> $deals
     *
     * @return list<array<string, mixed>>
     */
    public function summaries(array $deals): array
    {
        $stages = [];
        foreach ($this->store->stages(true) as $stage) {
            $stages[$stage->id()] = $stage;
        }
        $reasons = [];
        foreach ($this->store->lostReasons(true) as $reason) {
            $reasons[$reason->id()] = $reason;
        }
        $clients = [];

        return array_map(function (Deal $deal) use ($stages, $reasons, &$clients): array {
            $reasonId = $deal->lostReasonId();

            return [
                'id' => $deal->id(),
                'title' => $deal->title(),
                'status' => $deal->status()->value,
                'client' => $clients[$deal->clientPartyId()] ??= $this->client($deal),
                'owner_user_id' => $deal->ownerUserId(),
                'stage' => $this->stageReference($stages[$deal->stageId()] ?? null),
                'won_at' => JsonValues::timestamp($deal->wonAt()),
                'lost_at' => JsonValues::timestamp($deal->lostAt()),
                'lost_reason' => $this->reasonReference(null === $reasonId ? null : $reasons[$reasonId] ?? null),
                'created_at' => JsonValues::timestamp($deal->createdAt()),
                'updated_at' => JsonValues::timestamp($deal->updatedAt()),
            ];
        }, $deals);
    }

    /**
     * @return array<string, mixed>
     */
    public function line(DealLine $line): array
    {
        $price = $line->unitPrice();

        return [
            'id' => $line->id(),
            'position' => $line->position(),
            'product_id' => $line->productId(),
            'name' => $line->name(),
            'unit' => $line->unit(),
            'quantity' => $line->quantity(),
            'unit_price' => $price instanceof Money ? JsonValues::money($price) : null,
            'billing_kind' => $line->billingKind()->value,
            'recurrence_unit' => $line->recurrenceUnit()?->value,
            'interval' => $line->interval(),
            'cycles' => $line->cycles(),
            ...$this->project($line),
        ];
    }

    /**
     * The project of an hourly line and the billable time logged on it.
     *
     * @return array{project: array{id: string, name: ?string}|null, logged_billable_minutes: ?int}
     */
    private function project(DealLine $line): array
    {
        $id = $line->projectId();
        if (null === $id) {
            return ['project' => null, 'logged_billable_minutes' => null];
        }

        return ['project' => ['id' => $id, 'name' => $this->projects->find($id)?->name], 'logged_billable_minutes' => $this->projects->billableMinutes($id)];
    }

    /**
     * @return array<string, mixed>
     */
    public function stage(Stage $stage): array
    {
        return [
            'id' => $stage->id(),
            'key' => $stage->key(),
            'name' => $this->stageNames->of($stage),
            'position' => $stage->position(),
            'archived' => $stage->isArchived(),
            'archived_at' => JsonValues::timestamp($stage->archivedAt()),
            'created_at' => JsonValues::timestamp($stage->createdAt()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function lostReason(LostReason $reason): array
    {
        return [
            'id' => $reason->id(),
            'key' => $reason->key(),
            'name' => $this->reasonNames->of($reason),
            'position' => $reason->position(),
            'archived' => $reason->isArchived(),
            'archived_at' => JsonValues::timestamp($reason->archivedAt()),
            'created_at' => JsonValues::timestamp($reason->createdAt()),
        ];
    }

    /**
     * @return array{id: string, name: string}
     */
    private function client(Deal $deal): array
    {
        return ['id' => $deal->clientPartyId(), 'name' => $this->parties->find($deal->clientPartyId())->displayName ?? ''];
    }

    /**
     * @return array{id: string, key: string|null, name: string}|null
     */
    private function stageReference(?Stage $stage): ?array
    {
        return $stage instanceof Stage ? ['id' => $stage->id(), 'key' => $stage->key(), 'name' => $this->stageNames->of($stage)] : null;
    }

    /**
     * @return array{id: string, key: string|null, name: string}|null
     */
    private function reasonReference(?LostReason $reason): ?array
    {
        return $reason instanceof LostReason ? ['id' => $reason->id(), 'key' => $reason->key(), 'name' => $this->reasonNames->of($reason)] : null;
    }
}
