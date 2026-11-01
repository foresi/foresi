<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Messenger;

use App\Import\Application\Message\RunImportChunk;
use App\Shared\Domain\Id;
use App\Shared\Infrastructure\Messenger\KeepUndecodableMessages;
use App\Shared\Infrastructure\Messenger\UndecodableMessage;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection as TransportConnection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Worker;

/**
 * A message queued before ids became UUIDs names its import with a whole
 * number. It must not be lost or stop the worker: it lands in the failed
 * transport, where it can be looked at.
 */
final class UndecodableMessageTest extends KernelTestCase
{
    public function testAMessageWithAnIntegerImportIdLandsInTheFailedTransport(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $connection = $container->get('doctrine.dbal.default_connection');
        $queue = new TransportConnection(TransportConnection::buildConfiguration('doctrine://default?queue_name=async'), $connection);
        $queue->setup();
        // The worker of the test reads a real queue; the failed transport of the test environment is in memory.
        $async = new DoctrineTransport($queue, new KeepUndecodableMessages());
        $connection->insert('messenger_messages', [
            'body' => $this->legacyBody(),
            'headers' => '[]',
            'queue_name' => 'async',
            'created_at' => '2026-10-31 12:00:00',
            'available_at' => '2026-10-31 12:00:00',
        ]);

        $events = $container->get('event_dispatcher');
        $events->addSubscriber(new StopWorkerOnMessageLimitListener(1));
        $bus = $container->get('messenger.default_bus');
        new Worker(['async' => $async], $bus, $events)->run(['sleep' => 0]);

        self::assertSame(0, $queue->getMessageCount());
        $failed = $container->get('messenger.transport.failed');
        $kept = [...$failed->get()];
        self::assertCount(1, $kept);
        $message = $kept[0]->getMessage();
        self::assertInstanceOf(UndecodableMessage::class, $message);
        self::assertInstanceOf(SentToFailureTransportStamp::class, $kept[0]->last(SentToFailureTransportStamp::class));
        self::assertStringContainsString('importId', $message->reason);
    }

    public function testTheTransportsUseIt(): void
    {
        self::bootKernel();

        $serializer = self::getContainer()->get('messenger.default_serializer');

        self::assertInstanceOf(UndecodableMessage::class, $serializer->decode(['body' => 'not a message'])->getMessage());
    }

    public function testAReadableMessagePassesUnchanged(): void
    {
        $serializer = new KeepUndecodableMessages();
        $message = new RunImportChunk(Id::generate(), 3);

        self::assertEquals($message, $serializer->decode($serializer->encode(new Envelope($message)))->getMessage());
    }

    private function legacyBody(): string
    {
        $id = Id::generate();
        $serialized = serialize(new Envelope(new RunImportChunk($id, 0)));

        return addslashes(str_replace('s:36:"'.$id.'"', 'i:12', $serialized));
    }
}
