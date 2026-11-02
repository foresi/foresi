<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Tenancy\Infrastructure;

use App\Party\Domain\Address;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatStatus;
use App\Tenancy\Application\Schedule\TenantDirectory;
use App\Tenancy\Application\TenantClock;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Application\TenantZoneFallback;
use App\Tenancy\Domain\Tenant;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Infrastructure\Console\TenantTimezoneCommand;
use App\Tenancy\Infrastructure\Schedule\DoctrineTenantDirectory;
use App\Tests\Support\Registry\RecordingLogger;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Tester\CommandTester;

final class TenantClockTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private EntityManagerInterface $em;
    private TenantContext $context;
    private int $firms = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        // 23:30 UTC: already the next day in Prague, still the same one in New York.
        self::mockTime('2026-12-31 23:30:00');
        $container = self::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');
        $this->context = $container->get(TenantContext::class);

        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    public function testStoredZoneWins(): void
    {
        $tenant = $this->tenant('America/New_York');
        $this->firm($tenant, 'CZ');

        $this->context->switchTo($tenant);

        self::assertSame('America/New_York', $this->clock()->timezone()->getName());
        self::assertSame('2026-12-31 00:00:00 America/New_York', $this->clock()->today()->format('Y-m-d H:i:s e'));
        self::assertSame('America/New_York', $this->directory()->zones()[$tenant->toString()]->getName());
    }

    public function testFallsBackToTheFirstActiveFirmsCountry(): void
    {
        $tenant = $this->tenant(null);
        $archived = $this->firm($tenant, 'DE')->id();
        $this->context->switchTo($tenant);
        $this->em->find(LegalEntity::class, $archived)?->archive(new \DateTimeImmutable('2026-12-01'));
        $this->em->flush();
        self::assertNotFalse(self::getContainer()->get(Connection::class)->fetchOne('SELECT id FROM legal_entity WHERE id = :id AND archived_at IS NOT NULL', ['id' => $archived]), 'The first firm is archived.');
        $this->context->reset();
        $this->firm($tenant, 'CZ');
        $this->firm($tenant, 'DE');

        $this->context->switchTo($tenant);

        self::assertSame('Europe/Prague', $this->clock()->timezone()->getName());
        self::assertSame('2027-01-01 00:00:00 Europe/Prague', $this->clock()->today()->format('Y-m-d H:i:s e'));
    }

    public function testNoFirmIsUtc(): void
    {
        $tenant = $this->tenant(null);
        $unsupported = $this->tenant(null);
        $this->firm($unsupported, 'DE');

        $zones = $this->directory()->zones();
        $this->context->switchTo($tenant);

        self::assertSame('UTC', $this->clock()->timezone()->getName());
        self::assertSame('2026-12-31 00:00:00 UTC', $this->clock()->today()->format('Y-m-d H:i:s e'));
        self::assertSame('UTC', $zones[$tenant->toString()]->getName());
        self::assertSame('UTC', $zones[$unsupported->toString()]->getName(), 'A country without rules has no zone to lend.');
    }

    public function testZonesTakeAtMostTwoQueries(): void
    {
        $stored = $this->tenant('America/New_York');
        $czech = [];
        foreach (range(1, 4) as $ignored) {
            $tenant = $this->tenant(null);
            $this->firm($tenant, 'CZ');
            $this->firm($tenant, 'DE');
            $czech[] = $tenant->toString();
        }
        $bare = $this->tenant(null);
        $this->em->clear();

        $before = $this->queryCount();
        $zones = $this->directory()->zones();
        $used = $this->queryCount() - $before;

        self::assertLessThanOrEqual(2, $used, 'Queries used: '.$used);
        self::assertCount(6, $zones);
        self::assertSame('America/New_York', $zones[$stored->toString()]->getName());
        self::assertSame('UTC', $zones[$bare->toString()]->getName());
        foreach ($czech as $id) {
            self::assertSame('Europe/Prague', $zones[$id]->getName());
        }
    }

    public function testAStoredZoneNeedsNoFallbackQuery(): void
    {
        $this->tenant('Europe/Prague');
        $this->tenant('America/New_York');
        $this->em->clear();

        $before = $this->queryCount();
        $this->directory()->zones();

        self::assertSame(1, $this->queryCount() - $before);
    }

    public function testTheCommandStoresAndRefusesAnUnknownZone(): void
    {
        $tenant = $this->tenant(null);
        $tester = new CommandTester(new Application(self::$kernel ?? throw new \LogicException('No kernel.'))->find('app:tenant:timezone'));

        self::assertSame(Command::SUCCESS, $tester->execute(['tenant' => $tenant->toString(), 'zone' => 'Europe/Prague']));
        self::assertSame('Europe/Prague', $this->storedZone($tenant));

        self::assertSame(Command::FAILURE, $tester->execute(['tenant' => $tenant->toString(), 'zone' => 'Europe/Atlantis']));
        self::assertStringContainsString('Europe/Atlantis', $tester->getDisplay());
        self::assertSame('Europe/Prague', $this->storedZone($tenant), 'A refused zone leaves the stored one.');

        self::assertSame(Command::FAILURE, $tester->execute(['tenant' => $tenant->toString(), 'zone' => '+02:00']));
        self::assertSame('Europe/Prague', $this->storedZone($tenant), 'An offset is no zone: it has no summer time.');

        self::assertSame(Command::FAILURE, $tester->execute(['tenant' => TenantId::generate()->toString(), 'zone' => 'Europe/Prague']));
        self::assertStringContainsString('No tenant', $tester->getDisplay());

        self::assertSame(Command::SUCCESS, $tester->execute(['tenant' => $tenant->toString(), '--clear' => true]));
        self::assertNull($this->storedZone($tenant));
        self::assertStringContainsString('UTC', $tester->getDisplay(), 'Clearing tells which zone now applies.');

        self::assertSame(Command::FAILURE, $tester->execute(['tenant' => $tenant->toString()]));
        self::assertNull($this->storedZone($tenant));
    }

    public function testAStoredZoneThisPhpNoLongerKnowsFallsBackWithAWarning(): void
    {
        $tenant = $this->tenant(null);
        $this->firm($tenant, 'CZ');
        self::getContainer()->get(Connection::class)->executeStatement("UPDATE tenant SET timezone = 'Europe/Atlantis' WHERE id = :id", ['id' => $tenant->toString()]);
        $logger = new RecordingLogger();
        $directory = new DoctrineTenantDirectory(self::getContainer()->get(Connection::class), self::getContainer()->get(TenantZoneFallback::class), $logger);

        self::assertSame('Europe/Prague', $directory->zones()[$tenant->toString()]->getName());
        self::assertSame('Europe/Prague', $directory->zone($tenant)->getName());
        self::assertSame(2, $logger->warnings());
        self::assertStringContainsString('zone', $logger->records[0]['message']);
    }

    public function testTheCommandTakesCanonicalNamesOnlyAndLogsTheChange(): void
    {
        $tenant = $this->tenant(null);
        $logger = new RecordingLogger();
        $command = new TenantTimezoneCommand($this->em, $this->directory(), $logger);
        $io = new SymfonyStyle(new ArrayInput([]), new BufferedOutput());

        self::assertSame(Command::FAILURE, $command($io, $tenant->toString(), 'US/Eastern'), 'A backward-compatible alias is not stored.');
        self::assertNull($this->storedZone($tenant));
        self::assertSame(Command::SUCCESS, $command($io, $tenant->toString(), 'America/New_York'));
        self::assertSame(Command::SUCCESS, $command($io, $tenant->toString(), null, true));

        // Only the two changes are logged, not the refused alias.
        $levels = array_column($logger->records, 'level');
        $messages = array_column($logger->records, 'message');
        self::assertSame(['notice', 'notice'], $levels);
        self::assertStringContainsString('time zone', implode("\n", $messages));
    }

    public function testTheTenantRefusesAnOffsetZone(): void
    {
        $tenant = new Tenant(TenantId::generate(), 'Example Company', new \DateTimeImmutable('2026-10-01'));

        $this->expectException(\InvalidArgumentException::class);

        $tenant->changeTimezone(new \DateTimeZone('+01:00'));
    }

    private function tenant(?string $zone): TenantId
    {
        $id = TenantId::generate();
        $tenant = new Tenant($id, 'Example Company', new \DateTimeImmutable('2026-10-01'));
        $tenant->changeTimezone(null === $zone ? null : new \DateTimeZone($zone));
        $this->em->persist($tenant);
        $this->em->flush();

        return $id;
    }

    private function firm(TenantId $tenant, string $country): LegalEntity
    {
        $this->context->switchTo($tenant);
        // Valid Czech registration numbers in turn, so the firms of a tenant stay unique.
        $numbers = ['12345695', '12345679', '25596641'];
        $firm = new LegalEntity('Example Firm '.$country, new RegistrationNumber('CZ', $numbers[$this->firms++ % 3]), null, new Address('Example Street 1', 'Example City', '100 00', $country), VatStatus::NotRegistered, '', []);
        $this->em->persist($firm);
        $this->em->flush();
        $this->context->reset();

        return $firm;
    }

    private function storedZone(TenantId $tenant): ?string
    {
        $this->em->clear();
        $zone = self::getContainer()->get(Connection::class)->fetchOne('SELECT timezone FROM tenant WHERE id = :id', ['id' => $tenant->toString()]);
        self::assertTrue(null === $zone || \is_string($zone));

        return $zone;
    }

    private function queryCount(): int
    {
        return \count(self::getContainer()->get('doctrine.debug_data_holder')->getData()['default'] ?? []);
    }

    private function clock(): TenantClock
    {
        return self::getContainer()->get(TenantClock::class);
    }

    private function directory(): TenantDirectory
    {
        return self::getContainer()->get(TenantDirectory::class);
    }
}
