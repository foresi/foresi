<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Banking\Infrastructure;

use App\Banking\Application\GenerateAddress;
use App\Banking\Application\InboundMailbox;
use App\Banking\Application\PollBankInbox;
use App\Banking\Application\PollFailures;
use App\Banking\Application\ReceiveInboundMail;
use App\Banking\Infrastructure\CachePollFailures;
use App\Banking\Infrastructure\Imap\DisabledInboundMailbox;
use App\Banking\Infrastructure\Imap\ImapInboundMailbox;
use App\Tenancy\Application\TenantContext;
use App\Tests\Banking\Support\FailingInboundFiles;
use App\Tests\Banking\Support\InboundMailKit;
use App\Tests\Banking\Support\Scalars;
use App\Tests\Support\Mail\FakeInboundMailbox;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\AbstractLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

/**
 * Polling the operator mailbox moves each message once it is safely handled:
 * stored and duplicate to Done, unknown recipient to Unknown, too large and
 * unreadable to Rejected. A message whose handling failed stays in the inbox
 * for the next poll, which makes a crash between storing and moving harmless.
 */
final class PollBankInboxTest extends KernelTestCase
{
    private InboundMailKit $kit;
    private FakeInboundMailbox $mailbox;
    private Connection $connection;
    /** @var \ArrayObject<int, array{level: string, message: string, context: string}> */
    private \ArrayObject $logged;
    private PollFailures $failures;

    protected function setUp(): void
    {
        $this->failures = new CachePollFailures(new ArrayAdapter());
        self::bootKernel();
        Clock::set(new MockClock('2026-11-20 12:00:00', 'UTC'));
        $container = self::getContainer();
        $this->kit = InboundMailKit::seed($container->get(EntityManagerInterface::class), $container->get(TenantContext::class), $container->get(GenerateAddress::class));
        $mailbox = $container->get(InboundMailbox::class);
        self::assertInstanceOf(FakeInboundMailbox::class, $mailbox);
        $this->mailbox = $mailbox;
        $this->connection = $container->get(Connection::class);
        $this->logged = new \ArrayObject();
    }

    protected function tearDown(): void
    {
        FailingInboundFiles::failOn(null);
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testTheTaskPollsEveryFiveMinutes(): void
    {
        $poll = self::getContainer()->get(PollBankInbox::class);

        self::assertSame('bank-inbox', $poll->name());
        self::assertSame('5 minutes', $poll->every());
    }

    public function testPollingMovesDoneUnknownAndTooLargeAndLeavesFailuresInTheInbox(): void
    {
        $stored = $this->mailbox->deliver(InboundMailKit::mail($this->kit->address));
        $duplicate = $this->mailbox->deliver(InboundMailKit::mail($this->kit->address));
        $unknown = $this->mailbox->deliver(InboundMailKit::mail($this->kit->unknownAddress(), 'unknown@mail.example.com'));
        $large = $this->mailbox->deliver(str_repeat('a', ReceiveInboundMail::MAX_BYTES + 1));
        $unreadable = $this->mailbox->deliver("not a mail\r\n");
        $failing = $this->mailbox->deliver(InboundMailKit::mail($this->kit->address, 'failing@mail.example.com', ['X-Test' => 'fail-storage']));

        $this->poll(failOn: 'fail-storage')->run();

        self::assertSame([$stored, $duplicate], $this->mailbox->movedTo(FakeInboundMailbox::DONE));
        self::assertSame([$unknown], $this->mailbox->movedTo(FakeInboundMailbox::UNKNOWN));
        self::assertSame([$large, $unreadable], $this->mailbox->movedTo(FakeInboundMailbox::REJECTED));
        self::assertSame([$failing], $this->mailbox->inbox());
        self::assertSame(1, $this->rowCount());

        self::assertCount(1, $this->logged);
        $record = $this->logged[0];
        self::assertIsArray($record);
        self::assertSame('error', $record['level']);
        self::assertStringContainsString('stays in the inbox for the next poll', $record['message']);
        self::assertStringContainsString('"uid":"'.$failing.'","count":1,"max":5', $record['context']);
        // Never the address or its token: whoever reads the log could post as the bank.
        self::assertStringNotContainsString($this->kit->token, $record['message'].$record['context']);

        // The next poll stores it.
        $this->poll()->run();
        self::assertSame([], $this->mailbox->inbox());
        self::assertSame(2, $this->rowCount());
    }

    public function testPollingTakesAtMostFiftyOldestFirst(): void
    {
        foreach (array_reverse(range(1, 60)) as $uid) {
            $this->mailbox->deliverAs($uid, InboundMailKit::mail($this->kit->unknownAddress(), $uid.'@mail.example.com'));
        }

        $this->poll()->run();

        self::assertSame([50], $this->mailbox->limits());
        self::assertSame(range(1, 50), $this->mailbox->movedTo(FakeInboundMailbox::UNKNOWN));
        self::assertSame(range(51, 60), $this->mailbox->inbox());
    }

    public function testACrashBeforeMovingIsHarmlessOnTheNextPoll(): void
    {
        $uid = $this->mailbox->deliver(InboundMailKit::mail($this->kit->address));
        $this->mailbox->failNextMoveOf($uid);

        $this->poll()->run();
        self::assertSame([$uid], $this->mailbox->inbox());
        self::assertSame(1, $this->rowCount());

        $this->poll()->run();
        self::assertSame([$uid], $this->mailbox->movedTo(FakeInboundMailbox::DONE));
        self::assertSame(1, $this->rowCount());
        self::assertSame(1, Scalars::int($this->connection->fetchOne("SELECT COUNT(*) FROM inbound_message WHERE status = 'received'")));
    }

    public function testAMessageThatKeepsFailingIsMovedToRejectedAfterFiveTries(): void
    {
        $failing = $this->mailbox->deliver(InboundMailKit::mail($this->kit->address, 'failing@mail.example.com', ['X-Test' => 'fail-storage']));

        for ($run = 1; $run < PollBankInbox::MAX_FAILURES; ++$run) {
            $this->poll(failOn: 'fail-storage')->run();
            self::assertSame([$failing], $this->mailbox->inbox(), 'run '.$run);
        }
        $this->poll(failOn: 'fail-storage')->run();

        self::assertSame([$failing], $this->mailbox->movedTo(FakeInboundMailbox::REJECTED));
        self::assertSame([], $this->mailbox->inbox());
        self::assertSame(0, $this->rowCount());
        self::assertSame([], $this->failures->atLeast(1), 'Moved away, so forgotten.');
        $last = $this->logged[\count($this->logged) - 1];
        self::assertIsArray($last);
        self::assertStringContainsString('moved to Rejected', $last['message']);
        self::assertStringContainsString('"uid":"'.$failing.'","count":5', $last['context']);
    }

    public function testASuccessForgetsTheFailures(): void
    {
        $uid = $this->mailbox->deliver(InboundMailKit::mail($this->kit->address));
        $this->mailbox->failNextMoveOf($uid);
        $this->poll()->run();
        self::assertSame([(string) $uid], $this->failures->atLeast(1));

        $this->poll()->run();

        self::assertSame([], $this->failures->atLeast(1));
    }

    /**
     * Fifty messages the server will not move, ahead of a good one, fill every
     * batch: once given up on, they are left out, and the good one is read.
     */
    public function testFiftyUnmovableMessagesDoNotStallTheGoodOneBehindThem(): void
    {
        foreach (range(1, PollBankInbox::BATCH) as $n) {
            $this->mailbox->failEveryMoveOf($this->mailbox->deliver(InboundMailKit::mail($this->kit->unknownAddress(), $n.'@mail.example.com')));
        }
        $good = $this->mailbox->deliver(InboundMailKit::mail($this->kit->address, 'good@mail.example.com'));

        for ($run = 1; $run <= PollBankInbox::MAX_FAILURES; ++$run) {
            $this->poll()->run();
            self::assertSame(0, $this->rowCount(), 'run '.$run);
        }
        $this->poll()->run();

        self::assertSame([$good], $this->mailbox->movedTo(FakeInboundMailbox::DONE));
        self::assertSame(1, $this->rowCount());
        self::assertCount(PollBankInbox::BATCH, $this->mailbox->inbox(), 'The unmovable ones stay for the operator.');
        self::assertCount(PollBankInbox::BATCH, $this->failures->atLeast(PollBankInbox::MAX_FAILURES));
        $unmovable = array_values(array_filter($this->logged->getArrayCopy(), static fn (array $record): bool => str_contains($record['message'], 'cannot be moved')));
        self::assertCount(PollBankInbox::BATCH, $unmovable);
        self::assertStringContainsString('"uid":"1","count":5', $unmovable[0]['context']);
    }

    public function testAnEmptyHostDisablesPolling(): void
    {
        $mailbox = ImapInboundMailbox::fromSettings('', 993, 'ssl', '', '', 'INBOX');
        self::assertInstanceOf(DisabledInboundMailbox::class, $mailbox);
        self::assertSame([], $mailbox->fetch(PollBankInbox::BATCH));
        self::assertInstanceOf(ImapInboundMailbox::class, ImapInboundMailbox::fromSettings(' imap.example.com ', 993, 'ssl', 'bank@example.com', 'password', 'INBOX'));

        new PollBankInbox($mailbox, self::getContainer()->get(ReceiveInboundMail::class), $this->logger(), $this->failures)->run();
        self::assertCount(0, $this->logged);
    }

    /**
     * The polling task as the container builds it, with the logger of this test and,
     * when asked, a file store that fails for messages carrying a marker.
     */
    private function poll(?string $failOn = null): PollBankInbox
    {
        FailingInboundFiles::failOn($failOn);

        return new PollBankInbox($this->mailbox, self::getContainer()->get(ReceiveInboundMail::class), $this->logger(), $this->failures);
    }

    private function logger(): AbstractLogger
    {
        return new class($this->logged) extends AbstractLogger {
            /**
             * @param \ArrayObject<int, array{level: string, message: string, context: string}> $records
             */
            public function __construct(private readonly \ArrayObject $records)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $exception = $context['exception'] ?? null;
                if ($exception instanceof \Throwable) {
                    $context['exception'] = $exception::class.': '.$exception->getMessage();
                }
                $this->records->append(['level' => \is_string($level) ? $level : '', 'message' => (string) $message, 'context' => (string) json_encode($context)]);
            }
        };
    }

    private function rowCount(): int
    {
        return Scalars::int($this->connection->fetchOne('SELECT COUNT(*) FROM inbound_message'));
    }
}
