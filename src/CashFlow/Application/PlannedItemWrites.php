<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Application;

use Foresi\CashFlow\Contract\Event\PlannedDirection;
use Foresi\CashFlow\Contract\Event\PlannedItemChanged;
use Foresi\CashFlow\Contract\Event\PlannedItemCreated;
use Foresi\CashFlow\Contract\Event\PlannedItemDeleted;
use Foresi\CashFlow\Contract\Event\PlannedItemEnded;
use Foresi\CashFlow\Contract\Event\PlannedItemOccurrenceSkipped;
use Foresi\CashFlow\Contract\FlowDirection;
use Foresi\CashFlow\Contract\PairingState;
use Foresi\CashFlow\Contract\PlannedItemCounterparties;
use Foresi\CashFlow\Contract\PlannedVatMode;
use Foresi\CashFlow\Domain\DocumentPairing;
use Foresi\CashFlow\Domain\PlannedItem;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Shared\Application\TransactionalWork;
use Foresi\Shared\Contract\Event\EventRecorder;
use Foresi\Shared\Contract\Operation\Omitted;
use Foresi\Shared\Domain\Money;
use Foresi\Shared\Domain\Schedule\InvalidRecurrence;
use Foresi\Shared\Domain\Schedule\Recurrence;
use Foresi\Shared\Domain\Schedule\RecurrenceEnd;
use Foresi\Shared\Domain\Schedule\RecurringSchedule;
use Psr\Clock\ClockInterface;

/**
 * The writes of planned items over the API and MCP (ADR 0032). Each one runs
 * in one unit of work with its cash-flow.planned_item event, and only the
 * owners and members of the organisation write.
 */
final readonly class PlannedItemWrites implements PlannedItemCounterparties
{
    public function __construct(
        private CashFlowAccess $access,
        private PlannedItemRules $rules,
        private CashFlowStore $store,
        private TransactionalWork $transaction,
        private EventRecorder $events,
        private InstanceClock $instanceClock,
        private ClockInterface $clock,
        private Pairing $pairing,
    ) {
    }

    /**
     * @throws CashFlowRefused
     */
    public function create(CreatePlannedItemInput $input): PlannedItem
    {
        $this->access->denyUnlessStaff();
        $direction = FlowDirection::tryFrom($input->direction) ?? throw CashFlowRefused::invalid('direction_unknown', 'direction', 'The direction is in or out.');

        return $this->transaction->run(function () use ($input, $direction): PlannedItem {
            $terms = $this->rules->terms(new PlannedItemDraft($input->name, $input->category, $input->amount, $input->currency, $input->on, $input->repeat, $input->every, $input->lastDayOfMonth, $input->endsOn, $input->endsAfter, $input->counterpartyId, $input->included, $input->matchAccount, $input->matchVariableSymbol, $input->matchTolerance, $input->vatMode, $input->vatRate), $this->instanceClock->timezone());
            PlannedItemRules::checkVatDirection($terms, $direction);
            $firmId = $this->access->firm()->id();
            $item = new PlannedItem($direction, $terms, $this->clock->now());
            $this->store->add($item);
            $this->events->record(new PlannedItemCreated($item->id(), $firmId, $this->direction($item), self::decimal($item->amount()), $item->amount()->currency()->code(), $item->isRecurring()));
            // The documents of the counterparty recorded before the item may belong to it.
            if ($item->isRecurring()) {
                $this->reconsiderDocuments([$item->counterparty()?->id()]);
            }

            return $item;
        });
    }

    /**
     * @throws CashFlowRefused
     */
    public function change(ChangePlannedItemInput $input): PlannedItem
    {
        return $this->transaction->run(function () use ($input): PlannedItem {
            $item = $this->item($input->plannedItemId);
            $zone = $this->instanceClock->timezone();
            $was = $item->recurrence($zone);
            $before = $this->terms($item, $zone);
            $counterpartyBefore = $item->counterparty()?->id();
            $match = $item->matchRule();
            $tolerance = $match->toleranceMinor();
            // A one-off item has no end, and a new end replaces the other kind of end.
            $repeat = $this->given($input->repeat, $was?->unit()->value);
            $recurs = null !== $repeat;
            $endsOn = $this->given($input->endsOn, $recurs && $input->endsAfter instanceof Omitted ? $was?->endOn()?->format('Y-m-d') : null);
            $endsAfter = $this->given($input->endsAfter, $recurs && $input->endsOn instanceof Omitted ? $was?->endAfter() : null);
            // A new VAT mode without a rate keeps the rate, but none clears it.
            $vatMode = $this->given($input->vatMode, $item->vatMode()->value);
            $vatRate = $this->given($input->vatRate, PlannedVatMode::None->value === $vatMode ? null : $item->vatRate());
            $terms = $this->rules->terms(new PlannedItemDraft(
                $this->given($input->name, $item->name()),
                $this->given($input->category, $item->category()->value),
                $this->given($input->amount, self::decimal($item->amount())),
                $this->given($input->currency, $item->amount()->currency()->code()),
                $this->given($input->on, $item->firstOn()->format('Y-m-d')),
                $repeat,
                $this->given($input->every, $was?->every() ?? 1),
                $this->given($input->lastDayOfMonth, $recurs && ($was?->onLastDayOfMonth() ?? false)),
                $endsOn,
                $endsAfter,
                $this->given($input->counterpartyId, $item->counterparty()?->id()),
                $this->given($input->included, $item->isIncluded()),
                $this->given($input->matchAccount, $match->counterpartyAccount()),
                $this->given($input->matchVariableSymbol, $match->variableSymbol()),
                $this->given($input->matchTolerance, null === $tolerance ? null : self::decimal(Money::ofMinor($tolerance, $item->amount()->currency()))),
                $vatMode,
                $vatRate,
            ), $zone);
            PlannedItemRules::checkVatDirection($terms, $item->direction());
            $item->change($terms);
            $this->events->record(new PlannedItemChanged($item->id(), $this->access->firm()->id(), $this->direction($item), self::decimal($item->amount()), $item->amount()->currency()->code(), $item->isIncluded()));
            if ($before !== $this->terms($item, $zone)) {
                $this->reconsiderDocuments([$counterpartyBefore, $item->counterparty()?->id()]);
            }

            return $item;
        });
    }

    public function set(string $plannedItemId, string $counterpartyId): void
    {
        $this->change(new ChangePlannedItemInput($plannedItemId, counterpartyId: $counterpartyId));
    }

    /**
     * @throws CashFlowRefused
     */
    public function skip(SkipPlannedOccurrenceInput $input): PlannedItem
    {
        return $this->transaction->run(function () use ($input): PlannedItem {
            $item = $this->item($input->plannedItemId);
            $zone = $this->instanceClock->timezone();
            $on = PlannedItemRules::day($input->on, $zone, 'on');
            $recurrence = $item->recurrence($zone);
            $falls = $recurrence instanceof Recurrence
                ? RecurringSchedule::next($recurrence, 0, $on, 1)[0] ?? null
                : $item->firstOn();
            if ($falls?->format('Y-m-d') !== $on->format('Y-m-d')) {
                throw CashFlowRefused::invalid('not_an_occurrence', 'on', 'The planned item does not fall on this day; skip a day it falls on, as the forecast lists it.');
            }
            $item->skip($on);
            $this->events->record(new PlannedItemOccurrenceSkipped($item->id(), $this->access->firm()->id(), $this->direction($item), $on->format('Y-m-d')));
            // A document paired with the occurrence, or proposed for it, is looked at again now that it is gone.
            $this->pairing->reconsiderOccurrence($item, $on);

            return $item;
        });
    }

    /**
     * @throws CashFlowRefused
     */
    public function end(EndPlannedItemInput $input): PlannedItem
    {
        return $this->transaction->run(function () use ($input): PlannedItem {
            $item = $this->item($input->plannedItemId);
            $zone = $this->instanceClock->timezone();
            $on = PlannedItemRules::day($input->on, $zone, 'on');
            $recurrence = $item->recurrence($zone) ?? throw CashFlowRefused::conflict('not_recurring', 'A one-off planned item cannot be ended; change its day or delete it.');
            try {
                new Recurrence($recurrence->firstIssueOn(), $recurrence->unit(), $recurrence->every(), $recurrence->onLastDayOfMonth(), RecurrenceEnd::OnDate, $on, null);
            } catch (InvalidRecurrence $invalid) {
                throw CashFlowRefused::invalid('ends_before_start', 'on', $invalid->getMessage());
            }
            $item->endOn($on);
            $this->events->record(new PlannedItemEnded($item->id(), $this->access->firm()->id(), $this->direction($item), $on->format('Y-m-d')));
            // A document paired with an occurrence after the end, or proposed for one, is looked at again.
            $this->reconsiderDocuments([$item->counterparty()?->id()]);

            return $item;
        });
    }

    /**
     * @throws CashFlowRefused has_pairings while a document is paired with an occurrence of the item
     */
    public function delete(DeletePlannedItemInput $input): string
    {
        return $this->transaction->run(function () use ($input): string {
            $item = $this->item($input->plannedItemId);
            $paired = array_filter($this->store->pairings(plannedItemId: $item->id()), static fn (DocumentPairing $pairing): bool => \in_array($pairing->state(), [PairingState::Auto, PairingState::Confirmed], true));
            if ([] !== $paired) {
                throw CashFlowRefused::conflict('has_pairings', 'Documents are paired with occurrences of this planned item, so it cannot be deleted. End the recurring item instead (foresi_end_planned_item), or unpair the documents first (foresi_unpair_document).');
            }
            // The proposals that list the item let go of it before it goes, and look at their documents again once it is gone.
            $proposals = $this->pairing->release($item);
            $this->events->record(new PlannedItemDeleted($item->id(), $this->access->firm()->id(), $this->direction($item)));
            $this->store->remove($item);
            $this->store->flush();
            foreach ($proposals as $subject) {
                $this->pairing->reconsiderSubject($subject);
            }

            return $item->id();
        });
    }

    /**
     * What decides which documents belong to an item: its counterparty, direction, amount, VAT, tolerance,
     * schedule and whether the forecast includes it.
     *
     * @return array<string, mixed>
     */
    private function terms(PlannedItem $item, \DateTimeZone $zone): array
    {
        $recurrence = $item->recurrence($zone);

        return [
            'counterparty' => $item->counterparty()?->id(),
            'direction' => $item->direction()->value,
            'amount' => $item->amount()->minor().$item->amount()->currency()->code(),
            'vat' => [$item->vatMode()->value, $item->vatRate()],
            'tolerance' => $item->matchRule()->toleranceMinor(),
            'on' => $item->firstOn()->format('Y-m-d'),
            'schedule' => $recurrence instanceof Recurrence ? [$recurrence->unit()->value, $recurrence->every(), $recurrence->onLastDayOfMonth(), $recurrence->endOn()?->format('Y-m-d'), $recurrence->endAfter()] : null,
            'included' => $item->isIncluded(),
        ];
    }

    /**
     * Takes another look at the documents of these counterparties of the last 13 months that a person has not decided.
     *
     * @param list<string|null> $counterpartyIds
     */
    private function reconsiderDocuments(array $counterpartyIds): void
    {
        $today = $this->instanceClock->today();
        $from = $today->modify('-13 months');
        $to = $today->modify('+13 months');
        foreach (array_unique(array_filter($counterpartyIds, static fn (?string $id): bool => null !== $id)) as $counterpartyId) {
            $this->pairing->reconsiderOf($counterpartyId, $from, $to);
        }
    }

    /**
     * @throws CashFlowRefused
     */
    private function item(string $id): PlannedItem
    {
        $this->access->denyUnlessStaff();

        return $this->store->plannedItem($id) ?? throw CashFlowRefused::notFound('The organisation has no planned item with this id. Find it with foresi_list_planned_items.');
    }

    /**
     * @template T
     *
     * @param T|Omitted $value
     * @param T         $current
     *
     * @return T
     */
    private function given(mixed $value, mixed $current): mixed
    {
        return $value instanceof Omitted ? $current : $value;
    }

    private function direction(PlannedItem $item): PlannedDirection
    {
        return PlannedDirection::from($item->direction()->value);
    }

    /**
     * "1500.00": the amount as a decimal string in the minor units of its currency.
     */
    public static function decimal(Money $money): string
    {
        $units = $money->currency()->minorUnits();
        $minor = abs($money->minor());
        $unit = 10 ** $units;

        return \sprintf('%s%d.%0'.$units.'d', $money->isNegative() ? '-' : '', intdiv($minor, $unit), $minor % $unit);
    }
}
