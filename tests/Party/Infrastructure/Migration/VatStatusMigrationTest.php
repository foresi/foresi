<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Party\Infrastructure\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Version\Version;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Domain\VatStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The status the firm kept before the stretches existed becomes its first
 * stretch: undated, as its first day is not known.
 */
final class VatStatusMigrationTest extends KernelTestCase
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
    }

    public function testTheCurrentStatusBecomesOneUndatedStretch(): void
    {
        $this->firm(VatStatus::Registered);

        $this->migrate();

        self::assertSame([['registered', 'month', null]], $this->rows());
        self::assertSame(VatStatus::Registered->value, $this->connection()->fetchOne('SELECT vat_status FROM legal_entity'), 'the firm keeps its status');
    }

    public function testANonPayerGetsNoPeriodLength(): void
    {
        $this->firm(VatStatus::IdentifiedPerson);

        $this->migrate();

        self::assertSame([['identified_person', null, null]], $this->rows());
    }

    public function testRunningItAgainAddsNothing(): void
    {
        $this->firm(VatStatus::NotRegistered);
        $this->migrate();

        $this->migrate();

        self::assertSame([['not_registered', null, null]], $this->rows());
    }

    public function testWithoutAFirmNothingIsAdded(): void
    {
        $this->migrate();

        self::assertSame([], $this->rows());
    }

    public function testDownRemovesTheUndatedStretch(): void
    {
        $this->firm(VatStatus::Registered);
        $this->migrate();
        $this->connection()->insert('party_vat_status_period', ['id' => '01937f4e-0000-7000-8000-000000000001', 'status' => 'not_registered', 'period_length' => null, 'valid_from' => '2027-01-01']);

        $migration = $this->migration();
        $migration->down(new Schema());
        $this->execute($migration);

        self::assertSame([['not_registered', null, '2027-01-01']], $this->rows());
    }

    private function migrate(): void
    {
        $migration = $this->migration();
        $migration->up(new Schema());
        $this->execute($migration);
        $migration->postUp(new Schema());
    }

    /**
     * Runs the statements the migration planned; their parameters are positional.
     */
    private function execute(AbstractMigration $migration): void
    {
        foreach ($migration->getSql() as $query) {
            $this->connection()->executeStatement($query->getStatement(), array_values($query->getParameters()));
        }
    }

    /**
     * Loaded by Doctrine from the module's migrations directory, as a deploy
     * loads it, and built anew each time.
     */
    private function migration(): AbstractMigration
    {
        /** @var DependencyFactory $factory */
        $factory = self::getContainer()->get('doctrine.migrations.dependency_factory');
        $class = $factory->getMigrationRepository()->getMigration(new Version(\Foresi\Party\Migrations\Version20261122180001::class))->getMigration()::class;

        return new $class($this->connection(), $factory->getLogger());
    }

    private function firm(VatStatus $status): void
    {
        $this->em->persist(new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), VatStatus::NotRegistered === $status ? null : VatId::fromString('CZ12345695'), new Address('Example Street 1', 'Example City', '100 00', 'CZ'), $status, ''));
        $this->em->flush();
    }

    /**
     * @return list<array{mixed, mixed, mixed}>
     */
    private function rows(): array
    {
        return array_map(static fn (array $row): array => [$row['status'], $row['period_length'], $row['valid_from']], $this->connection()->fetchAllAssociative('SELECT status, period_length, valid_from FROM party_vat_status_period ORDER BY valid_from'));
    }

    private function connection(): Connection
    {
        return $this->em->getConnection();
    }
}
