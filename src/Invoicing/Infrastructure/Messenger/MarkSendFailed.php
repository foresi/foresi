<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Messenger;

use Foresi\Invoicing\Application\ConflictRetry;
use Foresi\Invoicing\Application\DeliverInvoiceSend;
use Foresi\Invoicing\Application\InvoiceSends;
use Foresi\Invoicing\Application\MailRefused;
use Foresi\Invoicing\Application\UnknownInvoiceSend;
use Foresi\Party\Application\TransactionalWork;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

/**
 * When the queue gives up on a delivery, the send says so: it fails with the
 * reason of the last try, so the firm sees it instead of a send queued for ever.
 * Only the server's own reason is kept, never the text of another error.
 *
 * The send's own tries decide, not the message that ran out: RequeueStuckSends
 * may have queued a second message for the same send, each with retries of its
 * own, and the first to run out must not cut the other short. A send with tries
 * left stays queued; the other message retries it, or, when none is left, the
 * job hands it back.
 */
final readonly class MarkSendFailed
{
    /** The first try and the five retries of the mail transport (config/packages/messenger.yaml). */
    public const int ATTEMPTS = 6;
    private const string GAVE_UP = 'The message could not be delivered.';

    public function __construct(
        private InvoiceSends $sends,
        private TransactionalWork $work,
        private ConflictRetry $retry,
        private ClockInterface $clock,
    ) {
    }

    #[AsEventListener]
    public function onFailure(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if ($event->willRetry() || !$message instanceof DeliverInvoiceSend) {
            return;
        }
        $refused = $this->refusal($event->getThrowable());
        try {
            $this->retry->run(fn (): null => $this->work->run(function () use ($message, $refused): null {
                $send = $this->sends->lock($message->sendId);
                if ($send->attempts() < self::ATTEMPTS) {
                    return null;
                }
                $send->fail(
                    \DateTimeImmutable::createFromInterface($this->clock->now()),
                    $refused->safeReason ?? self::GAVE_UP,
                    $refused->settingsProblem ?? false,
                    $refused->reasonCode ?? MailRefused::GAVE_UP,
                    $refused?->smtpCode,
                );

                return null;
            }));
        } catch (UnknownInvoiceSend) {
        }
    }

    private function refusal(\Throwable $failure): ?MailRefused
    {
        $chain = $failure instanceof HandlerFailedException ? $failure->getWrappedExceptions() : [$failure];
        foreach ($chain as $wrapped) {
            for ($cause = $wrapped; null !== $cause; $cause = $cause->getPrevious()) {
                if ($cause instanceof MailRefused) {
                    return $cause;
                }
            }
        }

        return null;
    }
}
