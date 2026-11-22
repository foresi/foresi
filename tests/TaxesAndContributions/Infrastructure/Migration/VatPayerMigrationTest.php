<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\TaxesAndContributions\Infrastructure\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Exception\IrreversibleMigration;
use Doctrine\Migrations\Version\Version;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Domain\VatStatus;
use Foresi\Party\Domain\VatStatusPeriod;
use Foresi\TaxesAndContributions\Contract\StatusKind;
use Foresi\TaxesAndContributions\Domain\OwnerProfile;
use Foresi\TaxesAndContributions\Domain\StatusPeriod;
use Foresi\TaxesAndContributions\Migrations\Version20261122210000;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

/**
 * The VAT payer statuses of the owner profile move to the firm, once (Review
 * focus 5): two VAT statuses never disagree after it.
 */
final class VatPayerMigrationTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        Clock::set(new MockClock('2026-11-22 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testTheMigrationMovesTheVatPayerStatusesToTheFirm(): void
    {
        $this->firm();
        // The stretch the Party migration made from the kept status.
        $this->em->persist(new VatStatusPeriod(VatStatus::NotRegistered, null, null));
        $profile = $this->profile();
        $this->em->persist(new StatusPeriod($profile, StatusKind::VatPayer, 'no', new \DateTimeImmutable('2026-01-01')));
        $this->em->persist(new StatusPeriod($profile, StatusKind::VatPayer, 'yes', new \DateTimeImmutable('2026-11-12')));
        $this->em->persist(new StatusPeriod($profile, StatusKind::Activity, 'main', new \DateTimeImmutable('2026-01-01')));
        $this->em->flush();

        $this->migrate();

        self::assertSame([['not_registered', null, '2026-01-01'], ['registered', 'month', '2026-11-12']], $this->stretches());
        self::assertSame([['activity', 'main']], $this->taxesRows(), 'No VAT payer status is left in the profile; the others stay.');
        self::assertSame('registered', $this->firmStatus(), 'The firm holds the status of today.');
    }

    public function testWithoutVatPayerStatusesNothingChanges(): void
    {
        $this->firm();
        $this->em->persist(new VatStatusPeriod(VatStatus::NotRegistered, null, null));
        $this->em->persist(new StatusPeriod($this->profile(), StatusKind::Activity, 'main', new \DateTimeImmutable('2026-01-01')));
        $this->em->flush();

        $this->migrate();

        self::assertSame([['not_registered', null, null]], $this->stretches());
        self::assertSame([['activity', 'main']], $this->taxesRows());
        self::assertSame('not_registered', $this->firmStatus());
    }

    public function testTodayIsTheDayInTheZoneOfTheFirmsCountry(): void
    {
        $this->firm();
        $profile = $this->profile();
        $this->em->persist(new StatusPeriod($profile, StatusKind::VatPayer, 'no', new \DateTimeImmutable('2026-01-01')));
        $this->em->persist(new StatusPeriod($profile, StatusKind::VatPayer, 'yes', new \DateTimeImmutable('2026-11-12')));
        $this->em->flush();
        // 22:30 UTC on the 11th is 23:30 in Prague, still the 11th.
        Clock::set(new MockClock('2026-11-11 22:30:00', 'UTC'));

        $this->migrate();

        self::assertSame('not_registered', $this->firmStatus());
    }

    public function testInTheHourBetweenPragueAndUtcMidnightThePayerDayHasBegun(): void
    {
        $this->firm();
        $profile = $this->profile();
        $this->em->persist(new StatusPeriod($profile, StatusKind::VatPayer, 'no', new \DateTimeImmutable('2026-01-01')));
        $this->em->persist(new StatusPeriod($profile, StatusKind::VatPayer, 'yes', new \DateTimeImmutable('2026-11-12')));
        $this->em->flush();
        // 23:30 UTC on the 11th is 00:30 in Prague on the 12th.
        Clock::set(new MockClock('2026-11-11 23:30:00', 'UTC'));

        $this->migrate();

        self::assertSame('registered', $this->firmStatus());
    }

    public function testBeforeTheFirstStatusBeginsTheFirmHoldsThatOne(): void
    {
        self::assertSame('registered', Version20261122210000::holding([['value' => 'yes', 'valid_from' => '2027-01-01'], ['value' => 'no', 'valid_from' => '2027-06-01']], '2026-11-22'));
        self::assertSame('not_registered', Version20261122210000::holding([['value' => 'yes', 'valid_from' => '2026-01-01'], ['value' => 'no', 'valid_from' => '2026-11-22']], '2026-11-22'));
        self::assertSame('registered', Version20261122210000::holding([['value' => 'no', 'valid_from' => '2026-01-01'], ['value' => 'yes', 'valid_from' => '2026-11-22 00:00:00']], '2026-11-22'), 'A day read with its time still counts by its day.');
    }

    public function testWithoutAFirmTheStatusesStillMove(): void
    {
        $this->em->persist(new StatusPeriod($this->profile(), StatusKind::VatPayer, 'yes', new \DateTimeImmutable('2026-11-12')));
        $this->em->flush();

        $this->migrate();

        self::assertSame([['registered', 'month', '2026-11-12']], $this->stretches());
        self::assertSame([], $this->taxesRows());
    }

    public function testItCannotBeUndone(): void
    {
        $this->expectException(IrreversibleMigration::class);

        $this->migration()->down(new Schema());
    }

    private function migrate(): void
    {
        $migration = $this->migration();
        $migration->up(new Schema());
        self::assertSame([], $migration->getSql(), 'The data moves in postUp(), not as planned statements.');
        $migration->postUp(new Schema());
        $this->em->clear();
    }

    /**
     * Loaded by Doctrine from the module's migrations directory, as a deploy
     * loads it, and built anew each time.
     */
    private function migration(): AbstractMigration
    {
        /** @var DependencyFactory $factory */
        $factory = self::getContainer()->get('doctrine.migrations.dependency_factory');
        $class = $factory->getMigrationRepository()->getMigration(new Version(Version20261122210000::class))->getMigration()::class;

        return new $class($this->connection(), $factory->getLogger());
    }

    private function firm(): void
    {
        $this->em->persist(new LegalEntity('Example Sole Trader', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, ''));
        $this->em->flush();
    }

    private function profile(): OwnerProfile
    {
        $profile = new OwnerProfile();
        $this->em->persist($profile);

        return $profile;
    }

    /**
     * @return list<array{mixed, mixed, mixed}>
     */
    private function stretches(): array
    {
        $rows = $this->connection()->fetchAllAssociative('SELECT status, period_length, valid_from FROM party_vat_status_period ORDER BY valid_from');

        return array_map(static fn (array $row): array => [$row['status'], $row['period_length'], \is_string($row['valid_from']) ? substr($row['valid_from'], 0, 10) : $row['valid_from']], $rows);
    }

    /**
     * @return list<array{mixed, mixed}>
     */
    private function taxesRows(): array
    {
        return array_map(static fn (array $row): array => [$row['kind'], $row['value']], $this->connection()->fetchAllAssociative('SELECT kind, value FROM taxes_status_period ORDER BY kind'));
    }

    private function firmStatus(): mixed
    {
        return $this->connection()->fetchOne('SELECT vat_status FROM legal_entity');
    }

    private function connection(): Connection
    {
        return $this->em->getConnection();
    }
}
