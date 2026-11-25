<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Webhooks\Infrastructure\Events;

use Foresi\Events\Infrastructure\Catalogue\EventCatalogue;
use Foresi\Shared\Application\Event\EventReadAccess;
use Foresi\Webhooks\Application\SubscribableEvents;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The event catalogue and the modules' access services, as the events feed asks
 * them. A module that has no access service is not readable.
 */
final readonly class CatalogueSubscribableEvents implements SubscribableEvents
{
    /**
     * @param iterable<EventReadAccess> $access
     */
    public function __construct(
        private EventCatalogue $catalogue,
        #[AutowireIterator(EventReadAccess::READ_ACCESS_TAG)]
        private iterable $access,
    ) {
    }

    public function names(string $pattern): array
    {
        return $this->catalogue->matching([$pattern]);
    }

    public function readableModules(): array
    {
        $modules = [];
        foreach ($this->access as $access) {
            if ($access->mayRead()) {
                $modules[] = $access->module();
            }
        }
        $modules = array_values(array_unique($modules));
        sort($modules, \SORT_STRING);

        return $modules;
    }
}
