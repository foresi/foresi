<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\TaxesAndContributions\Support;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Party\Contract\VatPeriodLength;
use Foresi\Party\Domain\VatStatus;
use Foresi\Party\Domain\VatStatusPeriod;

/**
 * The firm's VAT status from a day, as Taxes and contributions reads its VAT
 * payer days: "yes" a payer by the month, "no" not registered. Stored as it
 * is, without the checks of the firm's settings, which Party tests.
 */
final class FirmVat
{
    public static function from(EntityManagerInterface $em, string $value, string $day): void
    {
        $payer = 'yes' === $value;
        $em->persist(new VatStatusPeriod($payer ? VatStatus::Registered : VatStatus::NotRegistered, $payer ? VatPeriodLength::Month : null, new \DateTimeImmutable($day)));
        $em->flush();
    }
}
