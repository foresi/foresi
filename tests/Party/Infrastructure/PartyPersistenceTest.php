<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Party\Infrastructure;

use App\Party\Domain\Address;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Party\Domain\PartyKind;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatId;
use App\Party\Domain\VatStatus;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PartyPersistenceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private TenantContext $context;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get('doctrine.orm.entity_manager');
        $context = $container->get(TenantContext::class);
        $this->em = $em;
        $this->context = $context;

        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    public function testPartyRoundTripsWithAndWithoutIdentifiers(): void
    {
        $this->context->switchTo(TenantId::generate());
        $address = new Address('Example Street 1', 'Example City', '100 00', 'CZ');
        $this->em->persist(new Party(PartyKind::Company, 'Example Company', new RegistrationNumber('CZ', '12345687'), VatId::fromString('CZ12345687'), $address, 'testing@example.com', '+420000000000', true, true));
        $this->em->persist(new Party(PartyKind::Person, 'Test Person', null, null, $address, 'testing@example.com', '', true, false));
        $this->em->flush();
        $this->em->clear();

        $parties = $this->em->getRepository(Party::class)->findBy([], ['id' => 'ASC']);

        self::assertCount(2, $parties);
        self::assertSame('12345687', $parties[0]->registrationNumber()?->value);
        self::assertSame('CZ', $parties[0]->registrationNumber()->countryCode);
        self::assertSame('CZ12345687', $parties[0]->vatId()?->toString());
        self::assertSame(PartyKind::Company, $parties[0]->kind());
        self::assertSame('Example City', $parties[0]->address()->city);
        self::assertNull($parties[1]->registrationNumber());
        self::assertNull($parties[1]->vatId());
    }

    public function testLegalEntityRoundTrips(): void
    {
        $this->context->switchTo(TenantId::generate());
        $this->em->persist(new LegalEntity(
            'Example Company',
            new RegistrationNumber('CZ', '12345687'),
            null,
            new Address('Example Street 1', 'Example City', '100 00', 'CZ'),
            VatStatus::IdentifiedPerson,
            'Register entry 1',
            ['DE89370400440532013000', 'GB82WEST12345698765432'],
        ));
        $this->em->flush();
        $this->em->clear();

        $entity = $this->em->getRepository(LegalEntity::class)->findAll()[0];

        self::assertSame(['DE89370400440532013000', 'GB82WEST12345698765432'], $entity->bankAccounts());
        self::assertSame(VatStatus::IdentifiedPerson, $entity->vatStatus());
        self::assertNull($entity->vatId());
        self::assertSame('12345687', $entity->registrationNumber()->value);
    }

    public function testPartiesAreIsolatedPerTenant(): void
    {
        $address = new Address('Example Street 1', 'Example City', '100 00', 'CZ');
        $a = TenantId::generate();
        $this->context->switchTo($a);
        $this->em->persist(new Party(PartyKind::Person, 'Test Person', null, null, $address, '', '', true, false));
        $this->em->flush();
        $this->context->switchTo(TenantId::generate());

        self::assertSame([], $this->em->getRepository(Party::class)->findAll());
    }
}
