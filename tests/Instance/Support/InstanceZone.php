<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Instance\Support;

use Foresi\Instance\Application\ConfiguredZone;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Instance\Application\ZoneFallback;
use Symfony\Component\Clock\Clock;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Gives the instance of a test its zone, as APP_TIMEZONE would. Without it the
 * zone follows the country of the firm, which only Invoicing with a country
 * module knows; a test that counts days in Prague names Prague, so it counts
 * the same days with only the modules its own module requires.
 */
final class InstanceZone
{
    /**
     * Call it right after the kernel boots, before a service takes the clock. The
     * clock follows the one the test sets with Clock::set().
     */
    public static function set(ContainerInterface $container, string $zone = 'Europe/Prague'): void
    {
        $container->set(InstanceClock::class, new InstanceClock(new ConfiguredZone($zone), new class implements ZoneFallback {
            public function zone(): ?\DateTimeZone
            {
                return null;
            }
        }, new Clock()));
    }
}
