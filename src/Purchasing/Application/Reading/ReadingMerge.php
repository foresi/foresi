<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application\Reading;

/**
 * Merges the readings of several readers into one: the first reading with a
 * value for a field wins. The amounts (total, currency, VAT recap) are not
 * mixed: they all come from the first reading that has a total, so a total
 * from one reader never meets a recap or a currency from another.
 */
final class ReadingMerge
{
    /** The fields that only make sense together. */
    private const array AMOUNTS = [ReadField::Total, ReadField::Currency, ReadField::VatRecap];

    /**
     * @param list<Reading> $readings in reader order
     */
    public function merge(array $readings): Reading
    {
        $amounts = $this->firstWithTotal($readings);

        $values = [];
        foreach (($amounts->values ?? []) as $key => $value) {
            if (\in_array($value->field, self::AMOUNTS, true)) {
                $values[$key] = $value;
            }
        }

        foreach ($readings as $reading) {
            foreach ($reading->values as $key => $value) {
                if (isset($values[$key])) {
                    continue;
                }
                // With a total chosen, no other reader adds to its amounts.
                if (null !== $amounts && \in_array($value->field, self::AMOUNTS, true)) {
                    continue;
                }
                $values[$key] = $value;
            }
        }

        return new Reading($values);
    }

    /**
     * @param list<Reading> $readings
     */
    private function firstWithTotal(array $readings): ?Reading
    {
        foreach ($readings as $reading) {
            if (null !== $reading->get(ReadField::Total)) {
                return $reading;
            }
        }

        return null;
    }
}
