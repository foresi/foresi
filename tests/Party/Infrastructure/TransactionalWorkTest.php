<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Party\Infrastructure;

use App\Party\Application\TransactionalWork;
use App\Party\Domain\Party;
use App\Party\Domain\PartyKind;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TransactionalWorkTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private TenantContext $context;
    private TenantId $tenant;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->context = self::getContainer()->get(TenantContext::class);
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $this->tenant = TenantId::generate();
        $this->context->switchTo($this->tenant);
    }

    public function testCaughtDomainErrorLeavesAUsableSessionThatIsStillTenantScoped(): void
    {
        $this->em->persist(new Party(PartyKind::Person, 'Own Person', null, null, ''));
        $this->em->flush();
        $this->context->switchTo(TenantId::generate());
        $this->em->persist(new Party(PartyKind::Person, 'Foreign Person', null, null, ''));
        $this->em->flush();
        $this->context->switchTo($this->tenant);

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
        self::assertSame(['Own Person'], $names, 'the failed work is rolled back and the tenant filter is bound to the new session');
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
