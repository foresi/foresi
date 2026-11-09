<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Work\Infrastructure\Messenger;

use App\Tests\Support\Registry\RecordingLogger;
use App\Work\Application\NotificationMailer;
use App\Work\Application\SendNotificationMail;
use App\Work\Infrastructure\Messenger\MessengerNotificationMailQueue;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

final class MessengerNotificationMailQueueTest extends TestCase
{
    public function testTheMailIsQueuedWithTheDelay(): void
    {
        $bus = $this->bus();
        new MessengerNotificationMailQueue($bus, $this->mailer(true), new RecordingLogger(), 90)->queue($this->message());

        self::assertCount(1, $bus->dispatched);
        $message = $bus->dispatched[0]->getMessage();
        self::assertInstanceOf(SendNotificationMail::class, $message);
        self::assertSame('n1', $message->notificationId);
        self::assertSame(90000, $bus->dispatched[0]->last(DelayStamp::class)?->getDelay());
    }

    public function testNothingIsQueuedWithoutASystemMailer(): void
    {
        $bus = $this->bus();
        new MessengerNotificationMailQueue($bus, $this->mailer(false), new RecordingLogger(), 90)->queue($this->message());

        self::assertSame([], $bus->dispatched);
    }

    public function testAQueueThatCannotTakeItCostsTheMailOnly(): void
    {
        $bus = $this->bus(fail: true);
        $logger = new RecordingLogger();
        new MessengerNotificationMailQueue($bus, $this->mailer(true), $logger, 90)->queue($this->message());

        self::assertSame([['level' => 'warning', 'message' => 'The e-mail of the notification {notification} was not handed to the worker: {reason}']], $logger->records);
        self::assertSame([['notification' => 'n1', 'reason' => \RuntimeException::class]], $logger->contexts);
    }

    /**
     * @return MessageBusInterface&object{dispatched: list<Envelope>}
     */
    private function bus(bool $fail = false): MessageBusInterface
    {
        return new class($fail) implements MessageBusInterface {
            /** @var list<Envelope> */
            public array $dispatched = [];

            public function __construct(private readonly bool $fail)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                if ($this->fail) {
                    throw new \RuntimeException('The queue is down.');
                }
                $envelope = Envelope::wrap($message, $stamps);
                $this->dispatched[] = $envelope;

                return $envelope;
            }
        };
    }

    private function message(): SendNotificationMail
    {
        return new SendNotificationMail('n1', 'mentioned', 'a1', 'c1');
    }

    private function mailer(bool $configured): NotificationMailer
    {
        $mailer = self::createStub(NotificationMailer::class);
        $mailer->method('isConfigured')->willReturn($configured);

        return $mailer;
    }
}
