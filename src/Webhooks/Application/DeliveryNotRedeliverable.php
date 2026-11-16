<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Application;

/**
 * Only a finished delivery of an event is redelivered by hand: a pending one
 * is still on its schedule, and a ping is sent again with a new ping.
 */
final class DeliveryNotRedeliverable extends \DomainException
{
    public static function pending(): self
    {
        return new self('The delivery is still being attempted on its schedule.');
    }

    public static function ping(): self
    {
        return new self('A ping is not redelivered. Send a new ping instead.');
    }
}
