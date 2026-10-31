<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Import\Infrastructure;

use App\Import\Infrastructure\Doctrine\RowSavepoint;
use App\Party\Application\CreateParty;
use App\Party\Domain\Address;
use App\Party\Domain\Party;
use App\Party\Domain\PartyKind;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RowSavepointTest extends KernelTestCase
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
        self::getContainer()->get(TenantContext::class)->switchTo(TenantId::generate());
    }

    public function testAWrittenRowIsKeptAndLeavesNothingLoaded(): void
    {
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        $party = null;

        $failure = $this->savepoint()->attempt(function () use (&$party): void {
            $party = $this->party('Written');
        });

        self::assertNull($failure);
        self::assertInstanceOf(Party::class, $party);
        self::assertFalse($this->em->contains($party), 'detached, so the next row does not walk it on every flush');
        self::assertSame(1, $connection->getTransactionNestingLevel(), 'the chunk transaction is still open');
        $connection->commit();
        self::assertSame(['Written'], $connection->fetchFirstColumn('SELECT display_name FROM party'));
    }

    public function testAFailedRowIsRolledBackAloneAndLeavesNothingLoaded(): void
    {
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        $kept = $this->party('Kept');

        $failure = $this->savepoint()->attempt(function (): never {
            $this->party('Rolled back');
            throw new \RuntimeException('The row fails.');
        });

        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertFalse($this->em->contains($kept));
        self::assertSame(1, $connection->getTransactionNestingLevel());
        $connection->commit();
        self::assertSame(['Kept'], $connection->fetchFirstColumn('SELECT display_name FROM party'));
    }

    private function party(string $name): Party
    {
        return self::getContainer()->get(CreateParty::class)->create(PartyKind::Person, $name, null, null, '', '', [], new Address('', '', '', 'CZ'));
    }

    private function savepoint(): RowSavepoint
    {
        return self::getContainer()->get(RowSavepoint::class);
    }
}
