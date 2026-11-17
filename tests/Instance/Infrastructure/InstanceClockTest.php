<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Instance\Infrastructure;

use App\Instance\Application\ConfiguredZone;
use App\Instance\Application\InstanceClock;
use App\Instance\Application\ZoneFallback;
use App\Instance\Infrastructure\Clock\TimezoneEnvVarProcessor;
use App\Invoicing\Application\InvoicingCountries;
use App\Invoicing\Infrastructure\Doctrine\FirmCountryZones;
use App\Party\Domain\Address;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatStatus;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;

/**
 * The zone of the instance: APP_TIMEZONE when it is set, else the country of the
 * first active firm, else UTC.
 */
final class InstanceClockTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    /** A user-assigned ISO code no real country takes, so it never gains invoicing rules. */
    private const string NO_RULES = 'XX';

    private EntityManagerInterface $em;
    private int $firms = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        // 23:30 UTC: already the next day in Prague, still the same one in New York.
        self::mockTime('2026-12-31 23:30:00');
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');

        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    public function testTheConfiguredZoneWins(): void
    {
        $this->firm('CZ');
        $clock = $this->clock('America/New_York');

        self::assertSame('America/New_York', $clock->timezone()->getName());
        self::assertSame('2026-12-31 00:00:00 America/New_York', $clock->today()->format('Y-m-d H:i:s e'));
        self::assertSame('2026-12-31 18:30:00 America/New_York', $clock->clock()->now()->format('Y-m-d H:i:s e'));
    }

    public function testFallsBackToTheFirstActiveFirmsCountry(): void
    {
        $archived = $this->firm(self::NO_RULES)->id();
        $this->em->find(LegalEntity::class, $archived)?->archive(new \DateTimeImmutable('2026-12-01'));
        $this->em->flush();
        self::assertNotFalse(self::getContainer()->get(Connection::class)->fetchOne('SELECT id FROM legal_entity WHERE id = :id AND archived_at IS NOT NULL', ['id' => $archived]), 'The first firm is archived.');
        $this->firm('CZ');
        $this->firm(self::NO_RULES);

        $clock = $this->clock('');

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

    public function testACountryWithoutRulesHasNoZoneToLend(): void
    {
        $this->firm(self::NO_RULES);

        self::assertSame('UTC', $this->clock('')->timezone()->getName());
    }

    public function testTheContainersClockFollowsTheFirstFirm(): void
    {
        $this->firm('CZ');
        $clock = self::getContainer()->get(InstanceClock::class);

        self::assertSame('', self::getContainer()->getParameter('app.timezone'), 'The tests run with APP_TIMEZONE empty.');
        self::assertSame('Europe/Prague', $clock->timezone()->getName());
        self::assertSame('2027-01-01 00:00:00 Europe/Prague', $clock->today()->format('Y-m-d H:i:s e'));
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
     * container's firm-country fallback.
     */
    private function clock(string $configured): InstanceClock
    {
        $container = self::getContainer();

        return new InstanceClock(new ConfiguredZone($configured), new FirmCountryZones($container->get(Connection::class), $container->get(InvoicingCountries::class)), $container->get(ClockInterface::class));
    }

    private function firm(string $country): LegalEntity
    {
        // Valid Czech registration numbers in turn, so the firms stay unique.
        $numbers = ['12345695', '12345679', '25596641'];
        $firm = new LegalEntity('Example Firm '.$country, new RegistrationNumber('CZ', $numbers[$this->firms++ % 3]), null, new Address('Example Street 1', 'Example City', '100 00', $country), VatStatus::NotRegistered, '');
        $this->em->persist($firm);
        $this->em->flush();

        return $firm;
    }
}
