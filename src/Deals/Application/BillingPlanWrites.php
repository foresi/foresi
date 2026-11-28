<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Application;

use Brick\Math\BigDecimal;
use Foresi\Catalog\Contract\BillingKind;
use Foresi\Deals\Application\Api\CreateBillingEntryInput;
use Foresi\Deals\Application\Api\DeleteBillingEntryInput;
use Foresi\Deals\Application\Api\LinkBillingEntryInput;
use Foresi\Deals\Application\Api\UnlinkBillingEntryInput;
use Foresi\Deals\Application\Api\UpdateBillingEntryInput;
use Foresi\Deals\Contract\BillingDocument;
use Foresi\Deals\Contract\BillingDocumentType;
use Foresi\Deals\Contract\Event\BillingEntryCreated;
use Foresi\Deals\Contract\Event\BillingEntryDeleted;
use Foresi\Deals\Contract\Event\BillingEntryFulfilled;
use Foresi\Deals\Contract\Event\BillingEntryUnfulfilled;
use Foresi\Deals\Contract\Event\BillingEntryUpdated;
use Foresi\Deals\Domain\BillingEntryKind;
use Foresi\Deals\Domain\BillingPlanEntry;
use Foresi\Deals\Domain\Deal;
use Foresi\Deals\Domain\DealLine;
use Foresi\Deals\Domain\DealStatus;
use Foresi\Shared\Contract\Event\EventRecorder;
use Foresi\Shared\Contract\Operation\Omitted;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;

/**
 * The billing plan of a won deal: its planned invoices, from its screens, the
 * API and MCP alike (ADR 0032), each change in one unit of work with its
 * deals.billing_entry event. Only a won deal not in the trash is planned; the
 * plan of a deal reopened stays, and counts again once it is won again.
 *
 * The one-off entries of a currency never exceed the one-off value of the deal
 * in it; a percentage is only a way to enter the amount, which is stored. A
 * recurring line has one entry at most. Hourly lines are never planned: their
 * real hours are counted, not a guess (D4).
 *
 * A link to the document that fulfils an entry is checked here: an invoice for
 * a one-off entry, a recurring invoice for a recurring one, of the deal's
 * client, active, and fulfilling no other entry.
 */
final readonly class BillingPlanWrites
{
    private const string AMOUNT = '/^[0-9]{1,13}(\.[0-9]{1,2})?\z/';
    private const string PERCENT = '/^[0-9]{1,3}(\.[0-9]{1,2})?\z/';

    public function __construct(
        private SalesAccess $access,
        private SalesStore $store,
        private SalesWork $work,
        private EventRecorder $events,
        private SalesClock $clock,
        private BillingDocuments $documents,
    ) {
    }

    /**
     * @throws SalesRefused
     */
    public function create(CreateBillingEntryInput $input): BillingPlanEntry
    {
        $this->access->denyUnlessStaff();

        return $this->work->run(function () use ($input): BillingPlanEntry {
            $deal = $this->wonDeal($input->dealId);
            $kind = BillingEntryKind::tryFrom($input->kind) ?? throw SalesRefused::invalid('kind_unknown', 'kind', 'A planned invoice is one_off or recurring.');
            $expectedOn = $this->day($input->expectedOn);
            if (BillingEntryKind::Recurring === $kind) {
                foreach (['amount' => $input->amount, 'currency' => $input->currency, 'percent' => $input->percent] as $field => $value) {
                    if (null !== $value) {
                        throw SalesRefused::invalid('recurring_amount', $field, 'A recurring entry takes its amount from its line; leave out amount, currency and percent.');
                    }
                }
                $line = $this->recurringLine($deal, $input->lineId ?? throw SalesRefused::invalid('line_required', 'lineId', 'A recurring entry names the recurring line it follows.'));
                if ($this->store->billingEntryOfLine($line->id()) instanceof BillingPlanEntry) {
                    throw SalesRefused::conflict('line_planned', 'The line has a planned invoice already; change that one.', 'lineId');
                }
                $entry = SalesRefused::unless(fn (): BillingPlanEntry => BillingPlanEntry::recurring($deal->id(), $line->id(), $expectedOn, $input->text, $this->clock->now()));
            } else {
                if (null !== $input->lineId) {
                    throw SalesRefused::invalid('one_off_line', 'lineId', 'A one_off entry is an amount; only a recurring entry follows a line.');
                }
                $amount = $this->amount($deal, $input->amount, $input->currency, $input->percent);
                $this->checkPlanned($deal, $amount, null);
                $entry = SalesRefused::unless(fn (): BillingPlanEntry => BillingPlanEntry::oneOff($deal->id(), $amount, $expectedOn, $input->text, $this->clock->now()));
            }
            $this->store->add($entry);
            $this->events->record(new BillingEntryCreated($entry->id(), $deal->id(), $entry->kind()->value));

            return $entry;
        });
    }

    /**
     * @throws SalesRefused
     */
    public function update(UpdateBillingEntryInput $input): BillingPlanEntry
    {
        $this->access->denyUnlessStaff();

        return $this->work->run(function () use ($input): BillingPlanEntry {
            $deal = $this->wonDeal($input->dealId);
            $entry = $this->entry($deal, $input->entryId);
            $now = $this->clock->now();
            $amountGiven = !$input->amount instanceof Omitted || !$input->percent instanceof Omitted || !$input->currency instanceof Omitted;
            if ($amountGiven) {
                if (BillingEntryKind::OneOff !== $entry->kind()) {
                    throw SalesRefused::invalid('amount_not_one_off', 'amount', 'A recurring entry takes its amount from its line; change the line instead.');
                }
                $current = $entry->amount();
                $currency = $input->currency instanceof Omitted ? $current?->currency()->code() : $input->currency;
                $amount = $input->amount instanceof Omitted ? null : $input->amount;
                $percent = $input->percent instanceof Omitted ? null : $input->percent;
                // Only a currency given keeps the amount it had, in the new currency.
                if (null === $amount && null === $percent && $current instanceof Money) {
                    $amount = $this->decimal($current);
                }
                $money = $this->amount($deal, $amount, $currency, $percent);
                $this->checkPlanned($deal, $money, $entry);
                SalesRefused::unless(fn () => $entry->changeAmount($money, $now));
            }
            if (!$input->expectedOn instanceof Omitted) {
                $entry->planOn($this->day($input->expectedOn), $now);
            }
            if (!$input->text instanceof Omitted) {
                $text = $input->text;
                SalesRefused::unless(fn () => $entry->describe($text, $now));
            }
            $this->events->record(new BillingEntryUpdated($entry->id(), $deal->id(), $entry->kind()->value));

            return $entry;
        });
    }

    /**
     * @throws SalesRefused
     */
    public function delete(DeleteBillingEntryInput $input): Deal
    {
        $this->access->denyUnlessStaff();

        return $this->work->run(function () use ($input): Deal {
            $deal = $this->wonDeal($input->dealId);
            $entry = $this->entry($deal, $input->entryId);
            $this->store->remove($entry);
            $this->events->record(new BillingEntryDeleted($entry->id(), $deal->id(), $entry->kind()->value));

            return $deal;
        });
    }

    /**
     * @throws SalesRefused
     */
    public function link(LinkBillingEntryInput $input): BillingPlanEntry
    {
        $this->access->denyUnlessStaff();

        return $this->work->run(function () use ($input): BillingPlanEntry {
            $deal = $this->wonDeal($input->dealId);
            $entry = $this->entry($deal, $input->entryId);
            $type = BillingDocumentType::tryFrom($input->type) ?? throw SalesRefused::invalid('document_type_unknown', 'type', 'A planned invoice is fulfilled by an invoice or a recurring_invoice.');
            $expected = BillingEntryKind::OneOff === $entry->kind() ? BillingDocumentType::Invoice : BillingDocumentType::RecurringInvoice;
            if ($type !== $expected) {
                throw SalesRefused::invalid('document_type_mismatch', 'type', 'A one_off entry is fulfilled by an invoice, a recurring entry by a recurring_invoice.');
            }
            if ($entry->isFulfilled()) {
                throw SalesRefused::conflict('entry_fulfilled', 'The planned invoice is linked to a document already; unlink it first.');
            }
            $document = $this->documents->find($type, $input->documentId);
            if (!$document instanceof BillingDocument) {
                throw SalesRefused::invalid('document_unknown', 'documentId', 'There is no such invoice or recurring invoice. Find it with foresi_search_invoices or foresi_list_recurring_invoices.');
            }
            if ($document->clientPartyId !== $deal->clientPartyId()) {
                throw SalesRefused::invalid('document_other_client', 'documentId', 'Only an invoice or recurring invoice of the deal\'s client fulfils its plan.');
            }
            if (!$document->active) {
                throw SalesRefused::invalid('document_inactive', 'documentId', 'An invoice in the trash, or a recurring invoice ended or in the trash, fulfils nothing.');
            }
            foreach ($this->store->billingEntriesFulfilledBy($type, $document->id) as $other) {
                if ($other->id() !== $entry->id()) {
                    throw SalesRefused::conflict('document_taken', 'The document fulfils another planned invoice already.', 'documentId', ['deal_id' => $other->dealId(), 'entry_id' => $other->id()]);
                }
            }
            $entry->fulfil($type, $document->id, $this->clock->now());
            $this->events->record(new BillingEntryFulfilled($entry->id(), $deal->id(), $entry->kind()->value, $type->value, $document->id));

            return $entry;
        });
    }

    /**
     * Repeatable: an entry with no link stays as it is and records nothing.
     *
     * @throws SalesRefused
     */
    public function unlink(UnlinkBillingEntryInput $input): BillingPlanEntry
    {
        $this->access->denyUnlessStaff();

        return $this->work->run(function () use ($input): BillingPlanEntry {
            $deal = $this->wonDeal($input->dealId);
            $entry = $this->entry($deal, $input->entryId);
            $this->unfulfil($entry);

            return $entry;
        });
    }

    /**
     * The starting plan of a deal just won, when it has none: one one-off entry
     * per currency for the sum of its one-off lines, and one entry per priced
     * recurring line, each without a day. A deal won again keeps the plan it had.
     * Part of the unit of work of the win.
     */
    public function startPlan(Deal $deal): void
    {
        if ([] !== $this->store->billingPlanOf($deal->id())) {
            return;
        }
        $lines = $this->store->linesOf($deal->id());
        $now = $this->clock->now();
        $entries = [];
        foreach (DealValues::of($lines)->oneOff as $amount) {
            if ($this->positive($amount)) {
                $entries[] = BillingPlanEntry::oneOff($deal->id(), $amount, null, '', $now);
            }
        }
        foreach ($lines as $line) {
            $amount = BillingAmounts::ofLine($line);
            if ($amount instanceof Money && $this->positive($amount)) {
                $entries[] = BillingPlanEntry::recurring($deal->id(), $line->id(), null, $line->name(), $now);
            }
        }
        foreach ($entries as $entry) {
            $this->store->add($entry);
            $this->events->record(new BillingEntryCreated($entry->id(), $deal->id(), $entry->kind()->value));
        }
    }

    /**
     * Removes the entry of a line that was removed, or no longer recurs with a
     * price: it has nothing left to follow. Part of the unit of work of the line.
     */
    public function lineChanged(DealLine $line, bool $removed): void
    {
        $entry = $this->store->billingEntryOfLine($line->id());
        if (!$entry instanceof BillingPlanEntry || (!$removed && BillingKind::Recurring === $line->billingKind() && BillingAmounts::ofLine($line) instanceof Money)) {
            return;
        }
        $this->store->remove($entry);
        $this->events->record(new BillingEntryDeleted($entry->id(), $entry->dealId(), $entry->kind()->value));
    }

    /**
     * The document went to the trash, ended or was deleted: the entries it
     * fulfilled count again. Run by the listeners of Invoicing's events, with
     * no person acting; idempotent.
     */
    public function documentGone(BillingDocumentType $type, string $documentId): void
    {
        $this->work->run(function () use ($type, $documentId): void {
            foreach ($this->store->billingEntriesFulfilledBy($type, $documentId) as $entry) {
                $this->unfulfil($entry);
            }
        });
    }

    private function unfulfil(BillingPlanEntry $entry): void
    {
        $type = $entry->fulfilledType();
        $documentId = $entry->fulfilledId();
        if ($type instanceof BillingDocumentType && null !== $documentId && $entry->unfulfil($this->clock->now())) {
            $this->events->record(new BillingEntryUnfulfilled($entry->id(), $entry->dealId(), $entry->kind()->value, $type->value, $documentId));
        }
    }

    /**
     * The amount of a one-off entry: given, or a share of the deal's one-off
     * value in the currency. The currency may be left out when the deal's
     * one-off value has one currency only.
     *
     * @throws SalesRefused
     */
    private function amount(Deal $deal, ?string $amount, ?string $currency, ?string $percent): Money
    {
        $value = DealValues::of($this->store->linesOf($deal->id()))->oneOff;
        $currency ??= 1 === \count($value) ? array_key_first($value) : throw SalesRefused::invalid('currency_required', 'currency', 'Name the currency of the amount: the deal has one-off lines in '.(0 === \count($value) ? 'none' : 'several').'.');
        $code = Currency::tryFrom($currency);
        if (!$code instanceof Currency) {
            throw SalesRefused::invalid('currency_unknown', 'currency', 'The currency is not an ISO 4217 code; GET /api/v1/currencies lists them.');
        }
        if (null !== $amount && null !== $percent) {
            throw SalesRefused::invalid('amount_and_percent', 'percent', 'Give the amount or the percent, not both.');
        }
        if (null !== $percent) {
            $percent = trim($percent);
            if (1 !== preg_match(self::PERCENT, $percent) || (float) $percent <= 0.0 || (float) $percent > 100.0) {
                throw SalesRefused::invalid('percent_invalid', 'percent', 'The percent is more than 0 and at most 100, with up to two decimals, such as "50".');
            }
            $base = $value[$code->code()] ?? throw SalesRefused::invalid('percent_no_value', 'percent', 'The deal has no one-off value in this currency to take a percent of.');
            $share = BillingAmounts::share($base, $percent);
            if (!$this->positive($share)) {
                throw SalesRefused::invalid('amount_invalid', 'percent', 'A planned invoice is for more than zero.');
            }

            return $share;
        }
        $amount = trim($amount ?? '');
        if (1 !== preg_match(self::AMOUNT, $amount)) {
            throw SalesRefused::invalid('amount_invalid', 'amount', 'The amount is more than zero, a decimal number with at most two decimals, such as "5000.00".');
        }

        // A zero is refused by the entry itself, as amount_invalid.
        return Money::of($amount, $code);
    }

    /**
     * The one-off entries of a currency never exceed the deal's one-off value in it.
     *
     * @throws SalesRefused plan_exceeds_value
     */
    private function checkPlanned(Deal $deal, Money $amount, ?BillingPlanEntry $changed): void
    {
        $value = DealValues::of($this->store->linesOf($deal->id()))->oneOff[$amount->currency()->code()] ?? Money::ofMinor(0, $amount->currency());
        $planned = $amount;
        foreach ($this->store->billingPlanOf($deal->id()) as $entry) {
            $other = $entry->amount();
            if ($entry !== $changed && $other instanceof Money && $other->currency() === $amount->currency()) {
                $planned = $planned->plus($other);
            }
        }
        if ($planned->minor() > $value->minor()) {
            $left = $value->minus($planned->minus($amount));
            throw SalesRefused::invalid('plan_exceeds_value', 'amount', \sprintf('The one-off entries would exceed the deal\'s one-off value in %s; %s is left to plan.', $amount->currency()->code(), $left->isNegative() ? '0' : $this->decimal($left)));
        }
    }

    /**
     * @throws SalesRefused
     */
    private function recurringLine(Deal $deal, string $lineId): DealLine
    {
        foreach ($this->store->linesOf($deal->id()) as $line) {
            if ($line->id() !== $lineId) {
                continue;
            }
            if (BillingKind::Recurring !== $line->billingKind() || !BillingAmounts::ofLine($line) instanceof Money) {
                throw SalesRefused::invalid('line_not_recurring', 'lineId', 'Only a recurring line with a price is planned; an hourly line bills its real hours.');
            }

            return $line;
        }
        throw SalesRefused::invalid('line_unknown', 'lineId', 'The deal has no line with this id.');
    }

    /**
     * @throws SalesRefused not_found, deal_not_won
     */
    private function wonDeal(string $id): Deal
    {
        $deal = $this->store->deal($id);
        if (!$deal instanceof Deal || $deal->isTrashed()) {
            throw SalesRefused::notFound('There is no deal with this id. Find it with foresi_search_deals.');
        }
        if (DealStatus::Won !== $deal->status()) {
            throw SalesRefused::conflict('deal_not_won', 'Only a won deal has a billing plan to change; win it first.');
        }

        return $deal;
    }

    /**
     * @throws SalesRefused not_found
     */
    private function entry(Deal $deal, string $entryId): BillingPlanEntry
    {
        foreach ($this->store->billingPlanOf($deal->id()) as $entry) {
            if ($entry->id() === $entryId) {
                return $entry;
            }
        }
        throw SalesRefused::notFound('The deal has no planned invoice with this id. Read the deal with foresi_get_deal.');
    }

    private function positive(Money $money): bool
    {
        return !$money->isZero() && !$money->isNegative();
    }

    /** An amount as a decimal number, such as "5000.00". */
    private function decimal(Money $money): string
    {
        return (string) BigDecimal::ofUnscaledValue($money->minor(), $money->currency()->minorUnits());
    }

    /**
     * @throws SalesRefused expected_on_invalid
     */
    private function day(?string $day): ?\DateTimeImmutable
    {
        if (null === $day || '' === trim($day)) {
            return null;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($day));
        if (!$parsed instanceof \DateTimeImmutable || $parsed->format('Y-m-d') !== trim($day)) {
            throw SalesRefused::invalid('expected_on_invalid', 'expectedOn', 'The day is a date in ISO 8601, such as "2026-12-15".');
        }

        return $parsed;
    }
}
