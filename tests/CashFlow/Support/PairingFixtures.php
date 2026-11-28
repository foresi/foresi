<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\CashFlow\Support;

use Foresi\CashFlow\Contract\FlowCategory;
use Foresi\CashFlow\Contract\FlowDirection;
use Foresi\CashFlow\Domain\MatchRule;
use Foresi\CashFlow\Domain\PlannedItem;
use Foresi\CashFlow\Domain\PlannedItemTerms;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;

/**
 * Planned items and suppliers to pair documents with, built without the kernel.
 */
final class PairingFixtures
{
    public static function item(string $name = 'Cloud Host'): PlannedItem
    {
        return new PlannedItem(FlowDirection::Out, new PlannedItemTerms(
            $name,
            FlowCategory::Rent,
            Money::of('100.00', Currency::of('CZK')),
            new \DateTimeImmutable('2026-11-20'),
            null,
            null,
            true,
            MatchRule::none(),
        ), new \DateTimeImmutable('2026-11-17 10:00:00'));
    }

    public static function supplier(string $name = 'Supplier Example s.r.o.', string $number = '12345678'): Party
    {
        return new Party(PartyKind::Company, $name, new RegistrationNumber('CZ', $number), null, '');
    }
}
