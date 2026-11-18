<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Instance\Application;

/**
 * The zone the operator set for the instance in APP_TIMEZONE, or none. Only a
 * canonical name of the tz database is taken: an offset knows no summer time,
 * and an old alias such as US/Eastern may leave a later PHP. The value is
 * checked when the kernel boots, so a wrong one stops the instance at once.
 */
final readonly class ConfiguredZone
{
    private ?\DateTimeZone $zone;

    /**
     * @param string $name a canonical zone name, or '' for none
     *
     * @throws \InvalidArgumentException when the name is not a canonical zone
     */
    public function __construct(string $name)
    {
        $this->zone = '' === $name ? null : self::parse($name);
    }

    /**
     * @throws \InvalidArgumentException when the name is not a canonical zone
     */
    public static function parse(string $name): \DateTimeZone
    {
        if (!\in_array($name, \DateTimeZone::listIdentifiers(), true)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a canonical time zone of the tz database, such as Europe/Prague.', $name));
        }

        return new \DateTimeZone($name);
    }

    public function zone(): ?\DateTimeZone
    {
        return $this->zone;
    }
}
