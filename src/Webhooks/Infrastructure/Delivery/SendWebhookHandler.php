<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Webhooks\Infrastructure\Delivery;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Shared\Application\SealerUnavailable;
use Foresi\Webhooks\Application\AttemptResult;
use Foresi\Webhooks\Application\AttemptTrigger;
use Foresi\Webhooks\Application\RetrySchedule;
use Foresi\Webhooks\Application\SendWebhook;
use Foresi\Webhooks\Application\WebhookSecrets;
use Foresi\Webhooks\Domain\DisabledReason;
use Foresi\Webhooks\Domain\WebhookEndpoint;
use Foresi\Webhooks\Infrastructure\Dbal\WebhookDeliveryTable as T;
use Foresi\Webhooks\Infrastructure\Http\WebhookSender;
use Foresi\Webhooks\Infrastructure\Mail\EndpointDisabledMailer;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Makes one attempt of a delivery.
 *
 * It claims the delivery first, so of two messages for one attempt, a stale
 * delayed one and the sweep's, or two copies of a redelivery, only one sends;
 * the other finds it taken and does nothing. The request is made outside any
 * transaction. What it came to is recorded in a second one, with what follows:
 *
 * - a 2xx ends the delivery as succeeded, and the run of failures of the
 *   endpoint, whatever made the attempt;
 * - a 410 disables the endpoint at once and tells its owner;
 * - a failed scheduled attempt counts towards the five days that disable the
 *   endpoint, and is otherwise followed by the next attempt of the schedule,
 *   queued with its delay, or ends the delivery as failed after the tenth.
 *
 * A secret that cannot be opened, because the operator's keys are missing or
 * were rotated without the old one, fails the attempt as `internal` without a
 * request, and the delivery goes on along its schedule like any other failure.
 *
 * A ping and a manual redelivery are one attempt each: they are never retried
 * and their failures do not count. A delivery of a disabled endpoint ends as
 * failed at its next attempt, without a request.
 */
#[AsMessageHandler]
final readonly class SendWebhookHandler
{
    public function __construct(
        private WebhookDeliveries $deliveries,
        private EntityManagerInterface $em,
        private WebhookSecrets $secrets,
        private WebhookSender $sender,
        private EndpointDisabledMailer $mailer,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private \Random\Randomizer $random = new \Random\Randomizer(),
    ) {
    }

    public function __invoke(SendWebhook $message): void
    {
        $now = $this->clock->now();
        $delivery = $this->claim($message, $now);
        if (!$delivery instanceof ClaimedDelivery) {
            return;
        }

        $endpoint = $this->em->find(WebhookEndpoint::class, $delivery->endpointId);
        if (!$endpoint instanceof WebhookEndpoint || !$endpoint->isActive()) {
            $this->em->wrapInTransaction(function () use ($delivery, $message, $now): void {
                $this->deliveries->record($delivery, $message->trigger, $message->attemptId, new AttemptResult(null, 0, AttemptResult::ENDPOINT_DISABLED), $now);
                if (T::STATUS_PENDING === $delivery->status) {
                    $this->deliveries->finish($delivery->id, T::STATUS_FAILED, $now);
                }
            });

            return;
        }

        try {
            $secrets = array_map($this->secrets->open(...), $endpoint->signingSecrets($now));
        } catch (SealerUnavailable $e) {
            $this->logger->error('The secret of the webhook endpoint {endpoint} cannot be opened, so the attempt of the delivery {delivery} failed: {reason}', ['endpoint' => $endpoint->id(), 'delivery' => $delivery->id, 'reason' => $e->getMessage()]);
            $secrets = null;
        }
        $result = null === $secrets
            ? new AttemptResult(null, 0, AttemptResult::INTERNAL)
            : $this->sender->send($endpoint->url()->value, $delivery->eventId, $delivery->body, $secrets);

        $this->em->wrapInTransaction(function () use ($delivery, $endpoint, $message, $result, $now): void {
            $this->deliveries->record($delivery, $message->trigger, $message->attemptId, $result, $now);
            $this->settle($delivery, $endpoint, $message->trigger, $result);
        });
    }

    /**
     * A scheduled attempt claims a due delivery; a manual one or a ping claims
     * by the attempt its message names.
     */
    private function claim(SendWebhook $message, \DateTimeImmutable $now): ?ClaimedDelivery
    {
        if (AttemptTrigger::Schedule === $message->trigger) {
            $delivery = $this->deliveries->claim($message->deliveryId, $now);
        } elseif (null === $message->attemptId) {
            $this->logger->warning('A {trigger} attempt of the webhook delivery {delivery} names no attempt and was dropped.', ['trigger' => $message->trigger->value, 'delivery' => $message->deliveryId]);

            return null;
        } else {
            $delivery = $this->deliveries->claimAttempt($message->deliveryId, $message->attemptId, $message->trigger, $now);
        }

        return $delivery;
    }

    private function settle(ClaimedDelivery $delivery, WebhookEndpoint $endpoint, AttemptTrigger $trigger, AttemptResult $result): void
    {
        $now = $this->clock->now();
        if ($result->succeeded) {
            $endpoint->succeeded();
            $this->deliveries->finish($delivery->id, T::STATUS_SUCCEEDED, $now);
        } elseif ($result->gone) {
            $this->disable($endpoint, DisabledReason::Gone, $now);
            $this->finishFailedUnlessManual($delivery, $trigger, $now);
        } elseif (AttemptTrigger::Schedule === $trigger) {
            $delay = RetrySchedule::delayAfter($delivery->attempts + 1);
            if ($endpoint->failed($now)) {
                $this->disable($endpoint, DisabledReason::Failing, $now);
                $this->deliveries->finish($delivery->id, T::STATUS_FAILED, $now);
            } elseif (null === $delay) {
                $this->deliveries->finish($delivery->id, T::STATUS_FAILED, $now);
            } else {
                $this->next($delivery, RetrySchedule::withJitter($delay, $this->random), $now);
            }
        } else {
            $this->finishFailedUnlessManual($delivery, $trigger, $now);
        }
    }

    /**
     * A failed redelivery leaves the delivery as it was: it ended before.
     */
    private function finishFailedUnlessManual(ClaimedDelivery $delivery, AttemptTrigger $trigger, \DateTimeImmutable $now): void
    {
        if (AttemptTrigger::Manual !== $trigger) {
            $this->deliveries->finish($delivery->id, T::STATUS_FAILED, $now);
        }
    }

    private function next(ClaimedDelivery $delivery, int $seconds, \DateTimeImmutable $now): void
    {
        $this->deliveries->schedule($delivery->id, $now->modify(\sprintf('+%d seconds', $seconds)));
        $this->bus->dispatch(new Envelope(new SendWebhook($delivery->id, AttemptTrigger::Schedule), [
            new DelayStamp($seconds * 1000),
        ]));
    }

    private function disable(WebhookEndpoint $endpoint, DisabledReason $reason, \DateTimeImmutable $now): void
    {
        $endpoint->disable($reason, $now);
        $this->mailer->notify($endpoint->ownerId(), $endpoint->id(), $endpoint->url()->host(), $reason);
    }
}
