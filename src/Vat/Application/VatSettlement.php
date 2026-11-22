<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Application;

use Foresi\Vat\Contract\VatPayment;

/**
 * Which VAT period each bank movement to or from the tax office pays. The
 * amount never decides it; amounts are only compared to show a difference.
 *
 * A person's assignment wins, and a movement they marked as no VAT payment
 * is left out. Every other movement, oldest first, goes:
 * - paid to the office with its variable symbol: to the oldest unsettled
 *   period to pay whose last day is before the movement and whose window
 *   lasts to the due date of the period after it; else to the latest period
 *   before it that a movement already pays and whose window holds it, so
 *   several payments add up; else it stays unassigned;
 * - got back from the office account: to the oldest unsettled period to get
 *   back whose last day is before it, else unassigned (unverified:
 *   refund_account);
 * - paid to the office with another variable symbol: unassigned.
 * A period is settled once a movement pays it or a person marked it.
 */
final class VatSettlement
{
    /**
     * @param list<SettlementPeriod> $periods  oldest first
     * @param list<VatPayment>       $payments
     * @param array<string, ?string> $manual   the period of each movement a person assigned, null for no VAT payment
     *
     * @return array{array<string, list<PaymentView>>, list<PaymentView>} the payments of each period by its key, and those unassigned
     */
    public static function assign(array $periods, array $payments, array $manual): array
    {
        $keys = array_map(static fn (SettlementPeriod $period): string => $period->key, $periods);
        $paid = array_fill_keys($keys, []);
        $unassigned = [];
        usort($payments, static fn (VatPayment $a, VatPayment $b): int => [$a->on->format('Y-m-d'), $a->id] <=> [$b->on->format('Y-m-d'), $b->id]);
        $automatic = [];
        foreach ($payments as $payment) {
            if (!\array_key_exists($payment->id, $manual)) {
                $automatic[] = $payment;

                continue;
            }
            $key = $manual[$payment->id];
            if (null === $key) {
                continue;
            }
            if (isset($paid[$key])) {
                $paid[$key][] = new PaymentView($payment, $key, true);
            } else {
                $unassigned[] = new PaymentView($payment, null, true);
            }
        }
        foreach ($automatic as $payment) {
            $key = match ($payment->rule) {
                VatPayment::PAYABLE => self::payable($periods, $paid, $payment->on->format('Y-m-d')),
                VatPayment::REFUND => self::refund($periods, $paid, $payment->on->format('Y-m-d')),
                default => null,
            };
            if (null === $key) {
                $unassigned[] = new PaymentView($payment, null, false);
            } else {
                $paid[$key][] = new PaymentView($payment, $key, false);
            }
        }

        return [$paid, $unassigned];
    }

    /**
     * @param list<SettlementPeriod>           $periods
     * @param array<string, list<PaymentView>> $paid
     */
    private static function payable(array $periods, array $paid, string $day): ?string
    {
        foreach ($periods as $period) {
            if ($period->expected->minor() > 0 && !$period->marked && [] === $paid[$period->key] && self::holds($period, $day)) {
                return $period->key;
            }
        }
        foreach (array_reverse($periods) as $period) {
            if ([] !== $paid[$period->key] && self::holds($period, $day)) {
                return $period->key;
            }
        }

        return null;
    }

    /**
     * @param list<SettlementPeriod>           $periods
     * @param array<string, list<PaymentView>> $paid
     */
    private static function refund(array $periods, array $paid, string $day): ?string
    {
        foreach ($periods as $period) {
            if ($period->expected->isNegative() && !$period->marked && [] === $paid[$period->key] && $period->end->format('Y-m-d') < $day) {
                return $period->key;
            }
        }

        return null;
    }

    /**
     * Whether a payment on the day falls in the period's window: after its
     * last day, and at the latest on the due date of the period after it.
     */
    private static function holds(SettlementPeriod $period, string $day): bool
    {
        return $period->end->format('Y-m-d') < $day && (!$period->nextDueOn instanceof \DateTimeImmutable || $day <= $period->nextDueOn->format('Y-m-d'));
    }
}
