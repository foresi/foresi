<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Instance\Infrastructure;

use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Instance\Application\ConfiguredZone;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Instance\Application\ZoneFallback;
use Foresi\Instance\Infrastructure\Clock\TimezoneEnvVarProcessor;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;

/**
 * The zone of the instance: APP_TIMEZONE when it is set, else the zone an
 * installed module lends, else UTC. The zone of the first firm's country, which
 * Invoicing lends, is tested in Integration/Invoicing.
 */
final class InstanceClockTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    protected function setUp(): void
    {
        self::bootKernel();
        // 23:30 UTC: already the next day in Prague, still the same one in New York.
        self::mockTime('2026-12-31 23:30:00');
        $em = self::getContainer()->get('doctrine.orm.entity_manager');

        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    public function testTheConfiguredZoneWins(): void
    {
        $clock = new InstanceClock(new ConfiguredZone('America/New_York'), $this->fallback('Europe/Prague'), self::getContainer()->get(ClockInterface::class));

        self::assertSame('America/New_York', $clock->timezone()->getName());
        self::assertSame('2026-12-31 00:00:00 America/New_York', $clock->today()->format('Y-m-d H:i:s e'));
        self::assertSame('2026-12-31 18:30:00 America/New_York', $clock->clock()->now()->format('Y-m-d H:i:s e'));
    }

    public function testWithoutAConfiguredZoneTheFallbackLendsItsOwn(): void
    {
        $clock = new InstanceClock(new ConfiguredZone(''), $this->fallback('Europe/Prague'), self::getContainer()->get(ClockInterface::class));

        self::assertSame('Europe/Prague', $clock->timezone()->getName());
        self::assertSame('2027-01-01 00:00:00 Europe/Prague', $clock->today()->format('Y-m-d H:i:s e'));
        self::assertSame('2027-01-01 00:30:00 Europe/Prague', $clock->clock()->now()->format('Y-m-d H:i:s e'));
    }

    public function testNoFirmIsUtc(): void
    {
        $clock = $this->clock('');

        self::assertSame('UTC', $clock->timezone()->getName());
        self::assertSame('2026-12-31 00:00:00 UTC', $clock->today()->format('Y-m-d H:i:s e'));
    }

    public function testTheFallbackIsNotAskedWhenAZoneIsConfigured(): void
    {
        $fallback = new class implements ZoneFallback {
            public int $asked = 0;

            public function zone(): \DateTimeZone
            {
                ++$this->asked;

                return new \DateTimeZone('Europe/Prague');
            }
        };
        $clock = new InstanceClock(new ConfiguredZone('America/New_York'), $fallback, self::getContainer()->get(ClockInterface::class));

        self::assertSame('America/New_York', $clock->timezone()->getName());
        self::assertSame(0, $fallback->asked);
    }

    public function testTheConfiguredZoneTakesCanonicalNamesOnly(): void
    {
        self::assertNull(new ConfiguredZone('')->zone());
        self::assertSame('Europe/Prague', new ConfiguredZone('Europe/Prague')->zone()?->getName());
        foreach (['+01:00', 'CET', 'US/Eastern', 'Europe/Atlantis', 'europe/prague'] as $name) {
            try {
                new ConfiguredZone($name);
                self::fail(\sprintf('"%s" must be refused.', $name));
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($name, $e->getMessage());
            }
        }
    }

    public function testTheEnvironmentProcessorNamesTheVariableOfAWrongZone(): void
    {
        $processor = new TimezoneEnvVarProcessor();

        self::assertSame('Europe/Prague', $processor->getEnv('timezone', 'APP_TIMEZONE', static fn (): string => ' Europe/Prague '));
        self::assertSame('', $processor->getEnv('timezone', 'APP_TIMEZONE', static fn (): string => ''));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/APP_TIMEZONE.*Europe\/Atlantis/');
        $processor->getEnv('timezone', 'APP_TIMEZONE', static fn (): string => 'Europe/Atlantis');
    }

    public function testAWrongConfiguredZoneStopsTheKernelAtBoot(): void
    {
        // A kernel of its own: the one of the test case would be booted again to shut it down.
        $kernel = self::createKernel();
        $_SERVER['APP_TIMEZONE'] = $_ENV['APP_TIMEZONE'] = 'Europe/Atlantis';
        try {
            $kernel->boot();
            self::fail('A wrong APP_TIMEZONE must stop the boot.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('APP_TIMEZONE', $e->getMessage());
        } finally {
            unset($_SERVER['APP_TIMEZONE'], $_ENV['APP_TIMEZONE']);
        }
    }

    /**
     * The clock of the instance with the given APP_TIMEZONE ('' for none) and the
     * container's fallback, which is the zone of an installed module or none.
     */
    private function clock(string $configured): InstanceClock
    {
        $container = self::getContainer();

        return new InstanceClock(new ConfiguredZone($configured), $container->get(ZoneFallback::class), $container->get(ClockInterface::class));
    }

    private function fallback(string $zone): ZoneFallback
    {
        return new readonly class($zone) implements ZoneFallback {
            public function __construct(private string $zone)
            {
            }

            public function zone(): \DateTimeZone
            {
                return new \DateTimeZone($this->zone);
            }
        };
    }
}
