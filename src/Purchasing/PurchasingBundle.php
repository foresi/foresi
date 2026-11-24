<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing;

use Foresi\Purchasing\Application\Reading\ReadReceivedDocument;
use Foresi\Shared\Infrastructure\Module\ModuleBundle;

/**
 * Received documents from suppliers.
 */
final class PurchasingBundle extends ModuleBundle
{
    protected function messageRouting(): array
    {
        return [
            // The file of a received document is read after its draft was stored.
            ReadReceivedDocument::class => 'async',
        ];
    }
}
