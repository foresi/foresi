<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Application;

use Foresi\Instance\Application\InstanceClock;

/**
 * Reads the dates and times of the inputs. A time without an offset is a time
 * of the instance's zone, as people say it.
 */
final readonly class ActivityTimes
{
    public function __construct(private InstanceClock $clock)
    {
    }

    /** Now, in UTC, as every moment is kept. */
    public function now(): \DateTimeImmutable
    {
        return $this->clock->clock()->now()->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * @throws ActivitiesRefused invalid
     */
    public function date(?string $value, string $field): ?\DateTimeImmutable
    {
        if (null === $value || '' === trim($value)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value), $this->clock->timezone());
        if (!$date instanceof \DateTimeImmutable || $date->format('Y-m-d') !== trim($value)) {
            throw ActivitiesRefused::invalid('date_invalid', $field, 'A date is YYYY-MM-DD, such as 2026-12-01.');
        }

        return $date;
    }

    /**
     * @throws ActivitiesRefused invalid
     */
    public function dateTime(?string $value, string $field): ?\DateTimeImmutable
    {
        if (null === $value || '' === trim($value)) {
            return null;
        }
        $value = trim($value);
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?(Z|[+-]\d{2}:?\d{2})?$/', $value)) {
            throw ActivitiesRefused::invalid('time_invalid', $field, 'A time is ISO 8601, such as 2026-12-01T10:00:00+01:00; without an offset it is a time of the instance.');
        }
        try {
            return new \DateTimeImmutable($value, $this->clock->timezone());
        } catch (\Exception) {
            throw ActivitiesRefused::invalid('time_invalid', $field, 'A time is ISO 8601, such as 2026-12-01T10:00:00+01:00; without an offset it is a time of the instance.');
        }
    }
}
