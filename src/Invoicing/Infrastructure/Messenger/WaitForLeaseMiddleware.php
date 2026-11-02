<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Messenger;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Transport\Sync\SyncTransport;

/**
 * A delivery that finds the send claimed by another worker puts its message back
 * on the mail queue, delayed until the lease ends, and counts as handled. A
 * retry would do the same but use up one of the message's retries and push the
 * later ones further out; waiting is not a failure. The message keeps its
 * stamps, its tenant and its retry count among them.
 *
 * A synchronous transport would hand the message straight back, to wait again
 * at once: there the delivery fails as a retryable one, as it did before.
 */
final readonly class WaitForLeaseMiddleware implements MiddlewareInterface
{
    public function __construct(
        #[Autowire(service: 'messenger.transport.mail')]
        private SenderInterface $mail,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        try {
            return $stack->next()->handle($envelope, $stack);
        } catch (HandlerFailedException $failed) {
            $wrapped = $failed->getWrappedExceptions();
            $wait = 1 === \count($wrapped) ? reset($wrapped) : null;
            if (!$wait instanceof SendLeaseHeld) {
                throw $failed;
            }
            if ($this->mail instanceof SyncTransport) {
                throw new HandlerFailedException($envelope, [new RecoverableMessageHandlingException($wait->getMessage(), 0, $wait, $wait->delayMilliseconds)]);
            }
            $this->mail->send($envelope->withoutStampsOfType(NonSendableStampInterface::class)->withoutAll(DelayStamp::class)->with(new DelayStamp($wait->delayMilliseconds)));

            return $envelope;
        }
    }
}
