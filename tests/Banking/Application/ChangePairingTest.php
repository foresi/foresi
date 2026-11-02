<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Banking\Application;

use App\Banking\Application\ChangePairing;
use App\Banking\Application\FirmPairingRules;
use App\Banking\Application\PairingRules;
use App\Banking\Domain\FirmPairing;
use App\Party\Application\UnknownLegalEntity;
use App\Party\Domain\Address;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatStatus;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tests\Banking\Support\Scalars;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A firm's pairing switches: the defaults until the firm changes them, then its
 * own row, which no other firm shares.
 */
final class ChangePairingTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private TenantContext $context;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $this->context = self::getContainer()->get(TenantContext::class);
        $this->context->switchTo(TenantId::generate());
    }

    public function testDefaultsAreOnAndOffWithoutARow(): void
    {
        $firm = $this->firm('Example One s.r.o.');

        self::assertEquals(new PairingRules(autoPairing: true, smallDifference: false), $this->rules()->for($firm));
        self::assertSame(0, Scalars::int($this->connection()->fetchOne('SELECT COUNT(*) FROM firm_bank_pairing')));
    }

    public function testPairingSwitchesAreStoredPerFirm(): void
    {
        $one = $this->firm('Example One s.r.o.');
        $two = $this->firm('Example Two s.r.o.', '12345687');

        $this->change()->change($one->id(), false, true);
        $this->em->clear();

        self::assertSame([['legal_entity_id' => $one->id(), 'auto_pairing' => 0, 'small_difference' => 1]], $this->stored());
        self::assertEquals(new PairingRules(false, true), $this->rules()->for($this->reloaded($one)));
        self::assertEquals(new PairingRules(true, false), $this->rules()->for($this->reloaded($two)));

        $this->change()->change($one->id(), true, true);
        $this->em->clear();

        self::assertSame([['legal_entity_id' => $one->id(), 'auto_pairing' => 1, 'small_difference' => 1]], $this->stored());
        self::assertContains(FirmPairing::class, $this->connection()->fetchFirstColumn('SELECT entity_class FROM audit_entry'));
    }

    public function testAnotherTenantsFirmIsNotFound(): void
    {
        $firm = $this->firm('Example One s.r.o.');
        $this->context->switchTo(TenantId::generate());

        $this->expectException(UnknownLegalEntity::class);
        $this->change()->change($firm->id(), false, false);
    }

    private function firm(string $name, string $number = '12345695'): LegalEntity
    {
        $firm = new LegalEntity($name, new RegistrationNumber('CZ', $number), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, '');
        $this->em->persist($firm);
        $this->em->flush();

        return $firm;
    }

    private function reloaded(LegalEntity $firm): LegalEntity
    {
        return $this->em->find(LegalEntity::class, $firm->id()) ?? throw new \LogicException('The firm is gone.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function stored(): array
    {
        return array_map(static fn (array $row): array => ['legal_entity_id' => $row['legal_entity_id'], 'auto_pairing' => Scalars::int($row['auto_pairing']), 'small_difference' => Scalars::int($row['small_difference'])], $this->connection()->fetchAllAssociative('SELECT legal_entity_id, auto_pairing, small_difference FROM firm_bank_pairing'));
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
