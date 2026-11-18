<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Instance\Infrastructure;

use Foresi\Instance\Application\ZoneFallback;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The first zone an installed module knows, such as Invoicing from the
 * country of the first active firm. With none, the instance has no fallback
 * zone and uses the default one.
 */
final readonly class CollectedZoneFallback implements ZoneFallback
{
    /**
     * @param iterable<ZoneFallback> $providers
     */
    public function __construct(
        #[AutowireIterator(ZoneFallback::TAG)]
        private iterable $providers,
    ) {
    }

    public function zone(): ?\DateTimeZone
    {
        foreach ($this->providers as $provider) {
            $zone = $provider->zone();
            if (null !== $zone) {
                return $zone;
            }
        }

        return null;
    }
}
