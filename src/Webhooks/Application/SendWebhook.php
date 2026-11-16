<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Application;

use App\Tenancy\Application\TenantScopedMessage;

/**
 * Makes one attempt of one stored delivery, in the tenant the message carries.
 * A scheduled attempt claims the delivery when it is due; a manual redelivery
 * and a ping carry the id of the attempt they record, so a second message for
 * the same attempt finds it taken. The delivery row holds the schedule, so the
 * message is never retried by the queue.
 */
final readonly class SendWebhook implements TenantScopedMessage
{
    public function __construct(
        public string $deliveryId,
        public AttemptTrigger $trigger,
        public ?string $attemptId = null,
    ) {
    }
}
