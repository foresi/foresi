<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Party\Infrastructure;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Party\Application\TransactionalWork;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TransactionalWorkTest extends KernelTestCase
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

    public function testCaughtDomainErrorLeavesAUsableSession(): void
    {
        $this->em->persist(new Party(PartyKind::Person, 'Own Person', null, null, ''));
        $this->em->flush();

        try {
            $this->work()->run(function (): never {
                $this->em->persist(new Party(PartyKind::Person, 'Rolled Back', null, null, ''));
                $this->em->flush();

                throw new \DomainException('Rejected.');
            });
        } catch (\DomainException) {
        }

        self::assertTrue($this->em->isOpen());
        $names = $this->em->createQuery('SELECT p.displayName FROM '.Party::class.' p ORDER BY p.id')->getSingleColumnResult();
        self::assertSame(['Own Person'], $names, 'the failed work is rolled back');
    }

    public function testSuccessfulWorkReturnsItsResult(): void
    {
        $marker = new \stdClass();

        self::assertSame($marker, $this->work()->run(static fn (): \stdClass => $marker));
    }

    private function work(): TransactionalWork
    {
        return self::getContainer()->get(TransactionalWork::class);
    }
}
