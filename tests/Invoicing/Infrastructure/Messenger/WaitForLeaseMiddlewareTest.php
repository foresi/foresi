<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Infrastructure\Messenger;

use App\Invoicing\Application\DeliverInvoiceSend;
use App\Invoicing\Infrastructure\Messenger\SendLeaseHeld;
use App\Invoicing\Infrastructure\Messenger\WaitForLeaseMiddleware;
use App\Tenancy\Infrastructure\Messenger\TenantStamp;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Middleware\StackMiddleware;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Sync\SyncTransport;

final class WaitForLeaseMiddlewareTest extends TestCase
{
    public function testAHeldLeaseSendsTheMessageBackLaterWithItsRetryCount(): void
    {
        $queue = new InMemoryTransport();
        $received = new Envelope(new DeliverInvoiceSend('send-1'), [new TenantStamp('tenant-1'), new RedeliveryStamp(3), new DelayStamp(60_000), new ReceivedStamp('mail')]);

        $handled = new WaitForLeaseMiddleware($queue)->handle($received, $this->throwing(new SendLeaseHeld(185_000)));

        self::assertSame($received, $handled, 'handled: the worker acknowledges it');
        $sent = [...$queue->getSent()];
        self::assertCount(1, $sent);
        self::assertSame([185_000], array_map(static fn (DelayStamp $stamp): int => $stamp->getDelay(), $sent[0]->all(DelayStamp::class)));
        self::assertSame([3], array_map(static fn (RedeliveryStamp $stamp): int => $stamp->getRetryCount(), $sent[0]->all(RedeliveryStamp::class)));
        self::assertSame('tenant-1', $sent[0]->last(TenantStamp::class)?->tenantId);
        self::assertNull($sent[0]->last(ReceivedStamp::class));
    }

    public function testASynchronousTransportCannotWaitSoTheFailureStays(): void
    {
        $received = new Envelope(new DeliverInvoiceSend('send-1'), [new ReceivedStamp('sync')]);

        try {
            new WaitForLeaseMiddleware(new SyncTransport(new MessageBus()))->handle($received, $this->throwing(new SendLeaseHeld(185_000)));
            self::fail('Sending it again at once would wait for the lease in a loop.');
        } catch (HandlerFailedException $failed) {
            self::assertInstanceOf(RecoverableMessageHandlingException::class, $failed->getPrevious());
        }
    }

    public function testOtherFailuresAndDispatchesPassThrough(): void
    {
        $queue = new InMemoryTransport();
        $other = new \RuntimeException('Something else.');
        $received = new Envelope(new DeliverInvoiceSend('send-1'), [new ReceivedStamp('mail')]);

        try {
            new WaitForLeaseMiddleware($queue)->handle($received, $this->throwing($other));
            self::fail('Another failure is the retry strategy\'s.');
        } catch (HandlerFailedException $failed) {
            self::assertSame($other, $failed->getPrevious());
        }
        self::assertSame([], [...$queue->getSent()]);
    }

    private function throwing(\Throwable $failure): StackInterface
    {
        return new StackMiddleware(new readonly class($failure) implements MiddlewareInterface {
            public function __construct(private \Throwable $failure)
            {
            }

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                throw new HandlerFailedException($envelope, [$this->failure]);
            }
        });
    }
}
