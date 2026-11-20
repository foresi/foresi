<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Instance\Integration\Invoicing;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Instance\Application\ConfiguredZone;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Invoicing\Application\InvoicingCountries;
use Foresi\Invoicing\Infrastructure\FirmCountryZones;
use Foresi\Party\Application\InstanceFirm;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Without APP_TIMEZONE the instance takes the zone of the country of the first
 * active firm, which Invoicing lends when it is installed.
 */
final class FirmCountryZoneTest extends KernelTestCase
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

    public function testFallsBackToTheFirstFirmsCountry(): void
    {
        $this->firm('CZ');
        $this->firm(self::NO_RULES);

        $clock = $this->clock('');

        self::assertSame('Europe/Prague', $clock->timezone()->getName());
        self::assertSame('2027-01-01 00:00:00 Europe/Prague', $clock->today()->format('Y-m-d H:i:s e'));
        self::assertSame('2027-01-01 00:30:00 Europe/Prague', $clock->clock()->now()->format('Y-m-d H:i:s e'));
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

    /**
     * The clock of the instance with the given APP_TIMEZONE ('' for none) and the
     * zone of the first firm's country, as Invoicing lends it.
     */
    private function clock(string $configured): InstanceClock
    {
        $container = self::getContainer();

        return new InstanceClock(new ConfiguredZone($configured), new FirmCountryZones($container->get(InstanceFirm::class), $container->get(InvoicingCountries::class)), $container->get(ClockInterface::class));
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
