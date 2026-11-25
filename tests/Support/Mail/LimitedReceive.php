<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Support\Mail;

use Foresi\Instance\Application\InstanceClock;
use Foresi\Mail\Application\DailyLimit;
use Foresi\Mail\Application\InboundAddresses;
use Foresi\Mail\Application\InboundAddressStore;
use Foresi\Mail\Application\InboundFiles;
use Foresi\Mail\Application\InboundPurposes;
use Foresi\Mail\Application\MailMessages;
use Foresi\Mail\Application\MailReader;
use Foresi\Mail\Application\MailTransaction;
use Foresi\Mail\Application\NotifyOverLimit;
use Foresi\Mail\Application\ReceiveMail;
use Foresi\Mail\Application\SenderVerdict;
use Psr\Clock\ClockInterface;
use Psr\Log\AbstractLogger;

/**
 * The mail intake of the container with a daily limit of the test's own, as the
 * limit is a setting read when the container is built, and a logger that keeps
 * what it is told, as a test of the log reads it.
 */
final readonly class LimitedReceive
{
    /**
     * @param \ArrayObject<int, array{level: string, message: string, context: string}> $logged
     */
    private function __construct(public ReceiveMail $receive, public \ArrayObject $logged)
    {
    }

    /**
     * @return array{level: string, message: string, context: string}
     */
    public function line(int $index): array
    {
        return $this->logged[$index] ?? throw new \OutOfBoundsException('No such line was logged.');
    }

    /**
     * How many lines were logged so far.
     */
    public function logLines(): int
    {
        return \count($this->logged);
    }

    /**
     * @param \Closure(string): mixed $service the service of the test container by its id
     */
    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private static function get(\Closure $service, string $class): object
    {
        $found = $service($class);
        if (!$found instanceof $class) {
            throw new \LogicException(\sprintf('The service %s is not there.', $class));
        }

        return $found;
    }

    public static function of(\Closure $service, int $limit, InstanceClock $clock): self
    {
        $logged = new \ArrayObject();

        return new self(new ReceiveMail(
            self::get($service, MailReader::class),
            self::get($service, InboundAddresses::class),
            self::get($service, InboundAddressStore::class),
            self::get($service, InboundPurposes::class),
            self::get($service, MailMessages::class),
            self::get($service, InboundFiles::class),
            self::get($service, SenderVerdict::class),
            self::get($service, MailTransaction::class),
            self::get($service, ClockInterface::class),
            $daily = new DailyLimit(self::get($service, MailMessages::class), $clock, $limit),
            new NotifyOverLimit(self::get($service, InboundAddressStore::class), $clock, $daily, new class($logged) extends AbstractLogger {
                /**
                 * @param \ArrayObject<int, array{level: string, message: string, context: string}> $records
                 */
                public function __construct(private readonly \ArrayObject $records)
                {
                }

                public function log($level, string|\Stringable $message, array $context = []): void
                {
                    $this->records->append(['level' => \is_string($level) ? $level : '', 'message' => (string) $message, 'context' => (string) json_encode($context)]);
                }
            }),
        ), $logged);
    }
}
