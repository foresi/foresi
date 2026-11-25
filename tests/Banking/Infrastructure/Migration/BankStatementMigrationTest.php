<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Banking\Infrastructure\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Version\Version;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Banking\Application\MovementInput;
use Foresi\Banking\Application\RecordMovement;
use Foresi\Banking\Application\TrustVerdict;
use Foresi\Banking\Contract\MovementDirection;
use Foresi\Banking\Contract\MovementTrust;
use Foresi\Banking\Migrations\Version20261125100001;
use Foresi\Banking\Migrations\Version20261125100002;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\Party\Support\FirmAccounts;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Every movement recorded before the intake came by bank mail: it gets the mail
 * channel and, as its source, the parser that read its message, or "mail" when no
 * message names one.
 */
final class BankStatementMigrationTest extends KernelTestCase
{
    private const string MESSAGE = '01937f4e-0000-7000-8000-0000000000a1';
    private const array STEPS = [Version20261125100001::class, Version20261125100002::class];

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    public function testTheMovementsSoFarCameByMailFromTheirParser(): void
    {
        [$parsed, $unparsed] = $this->seed();
        foreach (array_reverse(self::STEPS) as $step) {
            $this->migrate($step, 'down');
        }
        self::assertFalse($this->connection()->createSchemaManager()->tablesExist(['bank_statement']));

        foreach (self::STEPS as $step) {
            $this->migrate($step, 'up');
        }

        $rows = $this->connection()->fetchAllKeyValue('SELECT id, channel || \' \' || source_id FROM bank_movement');
        self::assertSame(['mail cz.kb', 'mail mail'], [$rows[$parsed] ?? null, $rows[$unparsed] ?? null]);
        self::assertTrue($this->connection()->createSchemaManager()->tablesExist(['bank_statement']));
    }

    /**
     * @return array{string, string} the movement of a parsed message, and one of none
     */
    private function seed(): array
    {
        $this->em->persist(new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, ''));
        $account = FirmAccounts::add($this->em, 'CZ6508000000192000145399');
        $this->em->flush();
        $this->connection()->insert('inbound_message', [
            'id' => self::MESSAGE, 'bank_feed_id' => '01937f4e-0000-7000-8000-0000000000f1', 'mail_message_id' => self::MESSAGE,
            'status' => 'processed', 'trust' => 'verified', 'trust_basis' => 'dmarc', 'parser_id' => 'cz.kb', 'parser_version' => 1, 'attempts' => 1,
        ]);
        $record = self::getContainer()->get(RecordMovement::class);
        $verdict = new TrustVerdict(MovementTrust::Verified, TrustVerdict::DMARC);
        $input = static fn (?string $message, string $amount): MovementInput => new MovementInput($account->id(), $message, MovementDirection::Incoming, Money::of($amount, Currency::CZK), new \DateTimeImmutable('2026-11-20'), null, null, 'Example Buyer Ltd', '2026001', null, null, null, null);
        $parsed = $record->record($input(self::MESSAGE, '150.00'), $verdict)->id();
        $unparsed = $record->record($input(null, '160.00'), $verdict)->id();
        $this->em->flush();
        $this->em->clear();

        return [$parsed, $unparsed];
    }

    /**
     * Runs one step as Doctrine runs it: the statements it adds first, then what it
     * changes in the schema, then what it does after.
     *
     * @param class-string<AbstractMigration> $version
     */
    private function migrate(string $version, string $direction): void
    {
        $manager = $this->connection()->createSchemaManager();
        $from = $manager->introspectSchema();
        $to = clone $from;
        /** @var DependencyFactory $factory */
        $factory = self::getContainer()->get('doctrine.migrations.dependency_factory');
        $class = $factory->getMigrationRepository()->getMigration(new Version($version))->getMigration()::class;
        $migration = new $class($this->connection(), $factory->getLogger());
        'up' === $direction ? $migration->up($to) : $migration->down($to);
        foreach ($migration->getSql() as $query) {
            $this->connection()->executeStatement($query->getStatement(), array_values($query->getParameters()));
        }
        foreach ($this->connection()->getDatabasePlatform()->getAlterSchemaSQL($manager->createComparator()->compareSchemas($from, $to)) as $sql) {
            $this->connection()->executeStatement($sql);
        }
        'up' === $direction ? $migration->postUp($to) : $migration->postDown($to);
    }

    private function connection(): Connection
    {
        return $this->em->getConnection();
    }
}
