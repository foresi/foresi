<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Application;

use Foresi\Activities\Domain\ActivityConflict;
use Foresi\Activities\Domain\InvalidField;

/**
 * Turns what the domain refuses into the refusals of the use cases.
 */
final class ActivitiesInvalid
{
    /**
     * @template T
     *
     * @param callable(): T $change
     *
     * @return T
     *
     * @throws ActivitiesRefused
     */
    public static function unless(callable $change): mixed
    {
        try {
            return $change();
        } catch (InvalidField $invalid) {
            throw ActivitiesRefused::invalid('invalid', $invalid->field, $invalid->getMessage());
        } catch (ActivityConflict $conflict) {
            throw ActivitiesRefused::conflict($conflict->reason, $conflict->getMessage());
        }
    }
}
