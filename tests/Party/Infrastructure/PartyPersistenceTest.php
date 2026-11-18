<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Party\Infrastructure;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PartyRole;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Domain\VatStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PartyPersistenceTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get('doctrine.orm.entity_manager');
        $this->em = $em;

        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    public function testPartyRoundTripsWithAndWithoutIdentifiers(): void
    {
        $this->em->persist(new Party(PartyKind::Company, 'Example Company', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), '+420000000000', [PartyRole::Customer, PartyRole::Supplier]));
        $this->em->persist(new Party(PartyKind::Person, 'Test Person', null, null, ''));
        $this->em->flush();
        $this->em->clear();

        $parties = $this->em->getRepository(Party::class)->findBy([], ['id' => 'ASC']);

        self::assertCount(2, $parties);
        self::assertSame('12345695', $parties[0]->registrationNumber()?->value);
        self::assertSame('CZ', $parties[0]->registrationNumber()->countryCode);
        self::assertSame('CZ12345695', $parties[0]->vatId()?->toString());
        self::assertSame(PartyKind::Company, $parties[0]->kind());
        self::assertSame([PartyRole::Customer, PartyRole::Supplier], $parties[0]->roles());
        self::assertSame([], $parties[1]->roles());
        self::assertNull($parties[1]->registrationNumber());
        self::assertNull($parties[1]->vatId());
    }

    public function testLegalEntityRoundTrips(): void
    {
        $this->em->persist(new LegalEntity(
            'Example Company',
            new RegistrationNumber('CZ', '12345695'),
            null,
            new Address('Example Street 1', 'Example City', '100 00', 'CZ'),
            VatStatus::IdentifiedPerson,
            'Register entry 1',
        ));
        $this->em->flush();
        $this->em->clear();

        $entity = $this->em->getRepository(LegalEntity::class)->findAll()[0];

        self::assertSame(VatStatus::IdentifiedPerson, $entity->vatStatus());
        self::assertNull($entity->vatId());
        self::assertSame('12345695', $entity->registrationNumber()->value);
    }
}
