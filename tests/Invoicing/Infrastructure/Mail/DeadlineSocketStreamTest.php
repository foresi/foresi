<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Infrastructure\Mail;

use App\Invoicing\Infrastructure\Mail\DeadlineSocketStream;
use App\Invoicing\Infrastructure\Mail\SymfonyMailGateway;
use App\Invoicing\Infrastructure\Messenger\DeliverInvoiceSendHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Process\Process;

final class DeadlineSocketStreamTest extends TestCase
{
    /** @var resource|null */
    private $server;

    protected function tearDown(): void
    {
        if (\is_resource($this->server)) {
            fclose($this->server);
        }
    }

    public function testReadsLinesTheServerSendsTogether(): void
    {
        $stream = $this->connected(5.0);
        $peer = $this->accepted();
        fwrite($peer, "250-first\r\n250 second\r\n");

        self::assertSame("250-first\r\n", $stream->readLine());
        self::assertSame("250 second\r\n", $stream->readLine());
        self::assertFalse($stream->hasPendingData());

        $stream->write("QUIT\r\n");
        self::assertSame("QUIT\r\n", fread($peer, 100));
        $stream->terminate();
    }

    public function testASilentServerEndsAtTheDeadlineNotAtTheReadTimeout(): void
    {
        $stream = $this->connected(1.0);
        $stream->setTimeout(30.0);
        $peer = $this->accepted();
        $began = microtime(true);

        try {
            $stream->readLine();
            self::fail('A silent server outlasted the deadline.');
        } catch (TransportException $timedOut) {
            $took = microtime(true) - $began;
            self::assertGreaterThan(0.8, $took);
            self::assertLessThan(2.0, $took, 'the read timeout is 30 s, the deadline 1 s');
            self::assertSame('The conversation with the mail server ran past its deadline.', $timedOut->getMessage());
        }
        fclose($peer);
    }

    public function testAServerThatTricklesBytesEndsAtTheDeadline(): void
    {
        // Another process sends one byte of the greeting every 0.1 s, so no single read ever times out.
        $trickle = new Process([\PHP_BINARY, '-r', <<<'PHP'
            $server = stream_socket_server('tcp://127.0.0.1:0');
            echo stream_socket_get_name($server, false), "\n";
            $peer = stream_socket_accept($server, 10);
            for ($i = 0; $i < 100; ++$i) {
                if (false === @fwrite($peer, '2')) {
                    break;
                }
                usleep(100000);
            }
            PHP]);
        $trickle->start();
        $trickle->waitUntil(static fn (string $type, string $output): bool => str_contains($output, "\n"));
        [$host, $port] = explode(':', trim($trickle->getOutput()));

        $stream = new DeadlineSocketStream(1.0);
        $stream->setHost($host)->setPort((int) $port)->disableTls();
        $began = microtime(true);
        try {
            $stream->initialize();
            $stream->readLine();
            self::fail('A trickling server outlasted the deadline.');
        } catch (TransportException) {
            self::assertLessThan(2.0, microtime(true) - $began);
        } finally {
            $trickle->stop(0);
        }
    }

    public function testNothingIsWrittenAfterTheDeadline(): void
    {
        $stream = $this->connected(0.2);
        $peer = $this->accepted();
        usleep(300_000);

        try {
            $stream->write("MAIL FROM:<invoices@example.com>\r\n");
            self::fail('A write went out after the deadline.');
        } catch (TransportException) {
        }
        stream_set_blocking($peer, false);
        self::assertSame('', (string) fread($peer, 100));
    }

    public function testTwoDeliveriesFitInOneLease(): void
    {
        $lease = new \DateTimeImmutable('@0')->modify('+'.DeliverInvoiceSendHandler::LEASE)->getTimestamp();

        // The customer's mail and the firm's copy go under one claim.
        self::assertLessThan($lease, 2 * SymfonyMailGateway::DEADLINE_SECONDS + 60);
    }

    private function connected(float $deadline): DeadlineSocketStream
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($server);
        $this->server = $server;
        [$host, $port] = explode(':', (string) stream_socket_get_name($server, false));
        $stream = new DeadlineSocketStream($deadline);
        $stream->setHost($host)->setPort((int) $port)->disableTls();
        $stream->initialize();

        return $stream;
    }

    /**
     * @return resource
     */
    private function accepted()
    {
        self::assertIsResource($this->server);
        $peer = stream_socket_accept($this->server, 5);
        self::assertIsResource($peer);

        return $peer;
    }
}
