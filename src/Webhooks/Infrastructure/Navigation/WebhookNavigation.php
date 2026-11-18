<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Webhooks\Infrastructure\Navigation;

use Foresi\Ui\Navigation\NavigationArea;
use Foresi\Ui\Navigation\NavigationItem;
use Foresi\Ui\Navigation\NavigationItemProvider;
use Foresi\Webhooks\Application\WebhookAccess;
use Foresi\Webhooks\Infrastructure\Controller\WebhookEndpointController;

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
            yield new NavigationItem('settings', 'nav.item.webhooks', 'send', WebhookEndpointController::ROUTE, weight: 18, routePrefixes: ['app_webhook'], area: NavigationArea::SETTINGS);
        }
    }
}
