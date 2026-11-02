<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Tenancy\Infrastructure;

use App\Tenancy\Application\Schedule\RunOperatorTask;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Infrastructure\Schedule\ForesiSchedule;
use App\Tenancy\Infrastructure\Schedule\ScheduleTick;
use App\Tests\Import\Support\RunMessages;
use App\Tests\Tenancy\Support\CountingOperatorTask;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Trigger\PeriodicalTrigger;

/**
 * Operator tasks are installation work with no tenant, such as polling the
 * operator's bank inbox. The schedule sends each at its own pace, and a run
 * holds a lock, so a second worker at the same moment skips instead of doing
 * the work twice.
 */
final class OperatorTaskTest extends KernelTestCase
{
    private RunMessages $worker;
    private MessageBusInterface $bus;

    protected function setUp(): void
    {
        self::bootKernel();
        CountingOperatorTask::reset();
        $container = self::getContainer();
        $queue = $container->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $queue);
        $this->bus = $container->get(MessageBusInterface::class);
        $this->worker = new RunMessages($queue, $this->bus);
    }

    protected function tearDown(): void
    {
        CountingOperatorTask::reset();
        parent::tearDown();
    }

    public function testOperatorTasksRunWithoutATenantUnderALock(): void
    {
        // A second worker gets the same task while the first still runs it.
        CountingOperatorTask::during(function (): void {
            $this->deliver(new RunOperatorTask(CountingOperatorTask::NAME));
        });

        $this->bus->dispatch(new RunOperatorTask(CountingOperatorTask::NAME));
        self::assertSame(1, $this->worker->run());

        self::assertSame([false], CountingOperatorTask::runs());

        // The lock is released afterwards, so the next run goes ahead.
        $this->deliver(new RunOperatorTask(CountingOperatorTask::NAME));
        self::assertSame([false, false], CountingOperatorTask::runs());
    }

    public function testARunHeldElsewhereIsSkipped(): void
    {
        $lock = self::getContainer()->get(LockFactory::class)->createLock('operator-task-'.CountingOperatorTask::NAME);
        self::assertTrue($lock->acquire());

        try {
            $this->deliver(new RunOperatorTask(CountingOperatorTask::NAME));
        } finally {
            $lock->release();
        }

        self::assertSame([], CountingOperatorTask::runs());
    }

    public function testAFailedRunFreesTheLock(): void
    {
        CountingOperatorTask::failNext(1);

        $this->deliver(new RunOperatorTask(CountingOperatorTask::NAME));
        $this->deliver(new RunOperatorTask(CountingOperatorTask::NAME));

        self::assertSame([false, false], CountingOperatorTask::runs());
    }

    public function testAnOperatorTaskNeverRunsInATenant(): void
    {
        $context = self::getContainer()->get(TenantContext::class);
        $context->set(TenantId::generate());

        try {
            $this->bus->dispatch(new RunOperatorTask(CountingOperatorTask::NAME));
            $context->reset();
            $this->worker->run();
            self::fail('A task sent from inside a tenant must be refused.');
        } catch (HandlerFailedException $failure) {
            self::assertStringContainsString('without a tenant', $failure->getMessage());
        }

        self::assertSame([], CountingOperatorTask::runs());
    }

    public function testAnUnknownTaskIsRefused(): void
    {
        $this->expectException(HandlerFailedException::class);
        $this->expectExceptionMessageIsOrContains('No operator task is named "missing"');

        $this->deliver(new RunOperatorTask('missing'));
    }

    public function testTheScheduleSendsEveryOperatorTaskAtItsPace(): void
    {
        $schedule = self::getContainer()->get(ForesiSchedule::class)->getSchedule();

        $sent = [];
        foreach ($schedule->getRecurringMessages() as $recurring) {
            $trigger = $recurring->getTrigger();
            self::assertInstanceOf(PeriodicalTrigger::class, $trigger);
            foreach ($recurring->getProvider()->getMessages(new MessageContext('foresi', $recurring->getId(), $trigger, new \DateTimeImmutable())) as $message) {
                $sent[] = [$message instanceof RunOperatorTask ? $message->task : $message::class, (string) $trigger];
            }
        }

        self::assertContains([ScheduleTick::class, 'every 5 minutes'], $sent);
        self::assertContains(['bank-inbox', 'every 5 minutes'], $sent);
        self::assertContains([CountingOperatorTask::NAME, 'every 10 minutes'], $sent);
    }

    private function deliver(object $message): void
    {
        $this->bus->dispatch(new Envelope($message, [new ReceivedStamp('async')]));
    }
}
