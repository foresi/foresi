<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Application;

use Foresi\CashFlow\Contract\FlowCategory;
use Foresi\CashFlow\Contract\PlannedVatMode;
use Foresi\CashFlow\Domain\PlannedItem;
use Foresi\CashFlow\Domain\PlannedItemVat;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Party\Contract\FirmVatStatuses;
use Foresi\Party\Contract\VatRegistration;
use Foresi\Party\Contract\VatStretch;
use Foresi\Shared\Domain\Money;

/**
 * When the VAT of a planned item counts: only on the days the firm is a
 * registered VAT payer. On any other day the amount moves as stated, with no
 * VAT. A payer's item that states no VAT misses it, but for the categories
 * that never carry VAT, such as the VAT itself, taxes, insurance, loans and
 * the owner's draws.
 */
final readonly class PlannedItemVatRule
{
    /** The categories with no VAT, which the VAT periods do not count either. */
    public const array WITHOUT_VAT = [FlowCategory::Vat, FlowCategory::Taxes, FlowCategory::Insurance, FlowCategory::Loans, FlowCategory::OwnerDraws];

    public function __construct(private FirmVatStatuses $statuses, private InstanceClock $clock)
    {
    }

    private function isPayerOn(\DateTimeImmutable $day): bool
    {
        $stretch = $this->statuses->on(new \DateTimeImmutable($day->format('Y-m-d'), $this->clock->timezone()));

        return $stretch instanceof VatStretch && VatRegistration::Registered === $stretch->status;
    }

    /**
     * The flow and the VAT of an occurrence on the day; null when no VAT counts on it.
     */
    public function on(PlannedItem $item, \DateTimeImmutable $day): ?PlannedItemVat
    {
        $vat = $item->vat();

        return $vat instanceof PlannedItemVat && $this->isPayerOn($day) ? $vat : null;
    }

    /**
     * What the occurrence on the day moves: with the VAT on top when the item
     * states it and the firm is a payer that day, the item's amount otherwise.
     * This is the amount the forecast uses for the occurrence.
     */
    public function amountOn(PlannedItem $item, \DateTimeImmutable $day): Money
    {
        return $this->on($item, $day)->flow ?? $item->amount();
    }

    /**
     * Whether the item states no VAT while the firm is a payer today.
     */
    public function isMissing(PlannedItem $item): bool
    {
        return PlannedVatMode::None === $item->vatMode()
            && !\in_array($item->category(), self::WITHOUT_VAT, true)
            && $this->isPayerOn($this->clock->today());
    }
}
