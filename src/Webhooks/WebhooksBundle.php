<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Webhooks;

use Foresi\Shared\Infrastructure\Module\ModuleBundle;
use Foresi\Webhooks\Application\SendWebhook;

/**
 * Outbound webhooks: endpoints, their secrets and the deliveries of events.
 */
final class WebhooksBundle extends ModuleBundle
{
    protected function messageRouting(): array
    {
        return [
            SendWebhook::class => 'webhooks',
        ];
    }
}
