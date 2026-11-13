<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Application;

/**
 * The endpoint has no delivery with this id, or no longer: deliveries are kept
 * for thirty days.
 */
final class UnknownWebhookDelivery extends \DomainException
{
    public function __construct()
    {
        parent::__construct('No such webhook delivery.');
    }
}
