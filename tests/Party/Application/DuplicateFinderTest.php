<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Party\Application;

use App\Party\Domain\InvalidRegistrationNumber;
use App\Party\Domain\Party;
use App\Party\Domain\PartyKind;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatId;
use App\Party\Infrastructure\Doctrine\DoctrineDuplicateFinder;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DuplicateFinderTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private TenantContext $context;
    private DoctrineDuplicateFinder $finder;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get('doctrine.orm.entity_manager');
        $context = $container->get(TenantContext::class);
        $this->em = $em;
        $this->context = $context;
        $this->finder = new DoctrineDuplicateFinder($em);

        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $this->context->switchTo(TenantId::generate());
    }

    public function testMatchesARegistrationNumberWrittenWithSpaces(): void
    {
        $this->save($this->company('Example Company', '12345679'));

        $matches = $this->finder->find(new RegistrationNumber('CZ', '1234 5679'), null);

        self::assertCount(1, $matches);
        self::assertSame('Example Company', $matches[0]->displayName);
        self::assertTrue($matches[0]->sameRegistrationNumber);
        self::assertFalse($matches[0]->sameVatId);
        self::assertFalse($matches[0]->archived);
    }

    public function testMatchesAStoredNumberWithSpacesAgainstAPlainOne(): void
    {
        $this->save($this->company('Example Company', 'HRB 1'));

        self::assertCount(1, $this->finder->find(new RegistrationNumber('CZ', 'hrb1'), null));
    }

    public function testDoesNotMatchOtherNumbersOrOtherCountries(): void
    {
        $this->save($this->company('Example Company', '12345679'));

        self::assertSame([], $this->finder->find(new RegistrationNumber('CZ', '12345687'), null));
        self::assertSame([], $this->finder->find(new RegistrationNumber('SK', '12345679'), null));
    }

    public function testMatchesAVatIdEvenWithADifferentRegistrationNumber(): void
    {
        $this->save($this->company('Example Company', '12345679', 'CZ12345679'));

        $matches = $this->finder->find(new RegistrationNumber('CZ', '99999990'), VatId::fromString('CZ 12345679'));

        self::assertCount(1, $matches);
        self::assertTrue($matches[0]->sameVatId);
        self::assertFalse($matches[0]->sameRegistrationNumber);
    }

    public function testReportsBothMatchesOnOneParty(): void
    {
        $this->save($this->company('Example Company', '12345679', 'CZ12345679'));

        $matches = $this->finder->find(new RegistrationNumber('CZ', '12345679'), VatId::fromString('CZ12345679'));

        self::assertCount(1, $matches);
        self::assertTrue($matches[0]->sameRegistrationNumber);
        self::assertTrue($matches[0]->sameVatId);
    }

    public function testNothingToCompareFindsNothing(): void
    {
        $this->save($this->company('Example Company', '12345679'));

        self::assertSame([], $this->finder->find(null, null));
    }

    public function testArchivedPartiesAreIncludedAndFlaggedOrLeftOut(): void
    {
        $archived = $this->company('Archived Company', '12345679');
        $archived->archive(new \DateTimeImmutable('2026-10-30 10:00:00'));
        $this->save($archived);

        $with = $this->finder->find(new RegistrationNumber('CZ', '1234 5679'), null);
        $without = $this->finder->find(new RegistrationNumber('CZ', '1234 5679'), null, false);

        self::assertCount(1, $with);
        self::assertTrue($with[0]->archived);
        self::assertSame([], $without);
    }

    public function testNeverReturnsPartiesOfOtherTenants(): void
    {
        $this->save($this->company('Example Company', '12345679', 'CZ12345679'));
        $this->context->switchTo(TenantId::generate());

        self::assertSame([], $this->finder->find(new RegistrationNumber('CZ', '12345679'), VatId::fromString('CZ12345679')));
    }

    public function testAnUnusableRegistrationNumberIsNotMatchedAndCannotBeBuilt(): void
    {
        $this->save($this->company('Example Company', '12345679'));

        $this->expectException(InvalidRegistrationNumber::class);
        new RegistrationNumber('CZ', ' - ');
    }

    public function testFindsByTheStoredKeyAfterTheNumberChanged(): void
    {
        $party = $this->company('Example Company', '11111111');
        $this->save($party);
        $party->changeIdentifiers(new RegistrationNumber('CZ', '2222 2222'), null);
        $this->em->flush();

        self::assertSame([], $this->finder->find(new RegistrationNumber('CZ', '11111111'), null));
        self::assertCount(1, $this->finder->find(new RegistrationNumber('CZ', '22222222'), null));
    }

    public function testExcludesThePartyBeingEdited(): void
    {
        $edited = $this->company('Edited Company', '12345679');
        $other = $this->company('Other Company', '12345679');
        $this->save($edited);
        $this->save($other);
        $id = $this->em->getUnitOfWork()->getSingleIdentifierValue($edited);
        self::assertIsInt($id);

        $matches = $this->finder->find(new RegistrationNumber('CZ', '12345679'), null, true, $id);

        self::assertCount(1, $matches);
        self::assertSame('Other Company', $matches[0]->displayName);
    }

    private function company(string $name, string $number, ?string $vat = null): Party
    {
        return new Party(PartyKind::Company, $name, new RegistrationNumber('CZ', $number), null !== $vat ? VatId::fromString($vat) : null, '');
    }

    private function save(Party $party): void
    {
        $this->em->persist($party);
        $this->em->flush();
    }
}
