<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Infrastructure\Delivery;

use App\Tenancy\Application\Schedule\OperatorTask;
use App\Tenancy\Infrastructure\Messenger\TenantStamp;
use App\Webhooks\Application\AttemptTrigger;
use App\Webhooks\Application\SendWebhook;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Queues again the attempts of pending deliveries that are more than a minute
 * overdue: their message was lost, or the worker that claimed them died before
 * it recorded the attempt, so the lease of the claim ran out. A message that
 * was only late meets the new one at the claim, and one of them does nothing.
 * Pings are never due, so they are never queued again.
 */
final readonly class WebhookSweepTask implements OperatorTask
{
    public const string NAME = 'webhook-sweep';
    public const string GRACE = '-60 seconds';
    public const int LIMIT = 500;

    public function __construct(
        private WebhookDeliveries $deliveries,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function every(): string
    {
        return '5 minutes';
    }

    public function run(): void
    {
        foreach ($this->deliveries->dueBefore($this->clock->now()->modify(self::GRACE), self::LIMIT) as $due) {
            $this->bus->dispatch(new Envelope(new SendWebhook($due['id'], AttemptTrigger::Schedule), [new TenantStamp($due['tenant'])]));
        }
    }
}
