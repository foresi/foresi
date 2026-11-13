<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Infrastructure\Navigation;

use App\Ui\Navigation\NavigationItem;
use App\Ui\Navigation\NavigationItemProvider;
use App\Webhooks\Application\WebhookAccess;
use App\Webhooks\Infrastructure\Controller\WebhookEndpointController;

/**
 * The webhook endpoints in the settings, next to the API access, for a person
 * who may read the events of at least one module.
 */
final readonly class WebhookNavigation implements NavigationItemProvider
{
    public function __construct(private WebhookAccess $access)
    {
    }

    public function items(): iterable
    {
        if ([] !== $this->access->readableModules()) {
            yield new NavigationItem('settings', 'nav.item.webhooks', 'send', WebhookEndpointController::ROUTE, weight: 18, routePrefixes: ['app_webhook']);
        }
    }
}
