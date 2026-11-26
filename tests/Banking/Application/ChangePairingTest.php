<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Banking\Application;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Banking\Application\ChangePairing;
use Foresi\Banking\Application\FirmPairingRules;
use Foresi\Banking\Application\PairingRules;
use Foresi\Banking\Domain\FirmPairing;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;
use Foresi\Tests\Support\Scalars;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The pairing switches of the instance: the defaults until they are changed,
 * then the one row, which the next change updates.
 */
final class ChangePairingTest extends KernelTestCase
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
        $this->firm();
    }

    public function testDefaultsAreOnAndOffWithoutARow(): void
    {
        self::assertEquals(new PairingRules(autoPairing: true, smallDifference: false), $this->rules()->get());
        self::assertSame(0, Scalars::int($this->connection()->fetchOne('SELECT COUNT(*) FROM firm_bank_pairing')));
    }

    public function testPairingSwitchesAreStoredInOneRow(): void
    {
        $this->change()->change(false, true);
        $this->em->clear();

        self::assertSame([['auto_pairing' => 0, 'small_difference' => 1]], $this->stored());
        self::assertEquals(new PairingRules(false, true), $this->rules()->get());

        $this->change()->change(true, true);
        $this->em->clear();

        self::assertSame([['auto_pairing' => 1, 'small_difference' => 1]], $this->stored());
        self::assertContains(FirmPairing::class, $this->connection()->fetchFirstColumn('SELECT entity_class FROM audit_entry'));
    }

    public function testARowWrittenWithoutTheSwitchesHasTheDefaults(): void
    {
        $this->connection()->insert('firm_bank_pairing', ['id' => '01963c5e-0000-7000-8000-000000000001']);

        self::assertSame([['auto_pairing' => 1, 'small_difference' => 0]], $this->stored(), 'the database defaults are the ones of an instance without a row');
    }

    public function testASecondRowIsRefused(): void
    {
        $this->change()->change(false, false);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->connection()->insert('firm_bank_pairing', ['id' => '01963c5e-0000-7000-8000-000000000002']);
    }

    private function firm(): void
    {
        $this->em->persist(new LegalEntity('Example One s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, ''));
        $this->em->flush();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function stored(): array
    {
        return array_map(static fn (array $row): array => ['auto_pairing' => Scalars::int($row['auto_pairing']), 'small_difference' => Scalars::int($row['small_difference'])], $this->connection()->fetchAllAssociative('SELECT auto_pairing, small_difference FROM firm_bank_pairing'));
    }

    private function change(): ChangePairing
    {
        return self::getContainer()->get(ChangePairing::class);
    }

    private function rules(): FirmPairingRules
    {
        return self::getContainer()->get(FirmPairingRules::class);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
