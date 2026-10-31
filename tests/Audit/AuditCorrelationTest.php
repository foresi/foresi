<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Audit;

use App\Audit\Application\AuditCorrelation;
use App\Audit\Application\AuditTrail;
use App\Audit\Domain\AuditAction;
use App\Audit\Domain\AuditEntry;
use App\Party\Domain\Party;
use App\Party\Domain\PartyKind;
use App\Party\Domain\PartyRole;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatId;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AuditCorrelationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AuditCorrelation $correlation;
    private AuditTrail $trail;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get('doctrine.orm.entity_manager');
        $this->em = $em;
        $context = $container->get(TenantContext::class);
        $this->correlation = $container->get(AuditCorrelation::class);
        $this->trail = $container->get(AuditTrail::class);

        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $context->switchTo(TenantId::generate());
    }

    public function testCreatedUpdatedAndDeletedEntriesCarryTheCorrelationId(): void
    {
        $this->correlation->within('import:1', function (): void {
            $created = $this->party('Example Company');
            $this->em->persist($created);
            $this->em->flush();
            $created->rename('Renamed Company');
            $this->em->flush();
            $this->em->remove($created);
            $this->em->flush();
        });

        $entries = $this->entries();
        self::assertSame([AuditAction::Created, AuditAction::Updated, AuditAction::Deleted], array_map(static fn (AuditEntry $e): AuditAction => $e->action(), $entries));
        foreach ($entries as $entry) {
            self::assertSame('import:1', $entry->correlationId());
        }
    }

    public function testEntriesOutsideACorrelationHaveNone(): void
    {
        $party = $this->party('Example Company');
        $this->em->persist($party);
        $this->em->flush();
        $this->correlation->within('import:1', static fn (): null => null);
        $party->rename('Renamed Company');
        $this->em->flush();

        foreach ($this->entries() as $entry) {
            self::assertNull($entry->correlationId());
        }
        self::assertCount(2, $this->entries());
    }

    public function testCorrelationIsClearedWhenWorkThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        try {
            $this->correlation->within('import:1', static fn () => throw new \RuntimeException('boom'));
        } finally {
            self::assertNull($this->correlation->current());
        }
    }

    public function testWithinReturnsTheResultAndExposesTheCurrentId(): void
    {
        $result = $this->correlation->within('import:1', fn (): ?string => $this->correlation->current());

        self::assertSame('import:1', $result);
        self::assertNull($this->correlation->current());
    }

    public function testNestedDifferentCorrelationThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->correlation->within('import:1', fn () => $this->correlation->within('import-undo:1', static fn (): null => null));
    }

    public function testNestedSameCorrelationKeepsTheOuterOneAfterwards(): void
    {
        $this->correlation->within('import:1', function (): void {
            $this->correlation->within('import:1', static fn (): null => null);
            self::assertSame('import:1', $this->correlation->current());
        });
        self::assertNull($this->correlation->current());
    }

    public function testResetClearsTheCurrentId(): void
    {
        try {
            $this->correlation->within('import:1', function (): void {
                $this->correlation->reset();
                self::assertNull($this->correlation->current());
            });
        } finally {
            self::assertNull($this->correlation->current());
        }
    }

    public function testTheKernelResetsTheServiceBetweenMessages(): void
    {
        $this->correlation->within('import:1', function (): void {
            self::getContainer()->get('services_resetter')->reset();
            self::assertNull($this->correlation->current());
        });
    }

    public function testRejectsAnEmptyOrTooLongId(): void
    {
        foreach (['', str_repeat('a', 65)] as $id) {
            try {
                $this->correlation->within($id, static fn (): null => null);
                self::fail('Invalid id accepted.');
            } catch (\InvalidArgumentException) {
                self::assertNull($this->correlation->current());
            }
        }
        self::assertSame(64, $this->correlation->within(str_repeat('a', 64), fn (): int => \strlen((string) $this->correlation->current())));
    }

    public function testChangedSinceIgnoresEntriesOfTheGivenCorrelation(): void
    {
        $since = new \DateTimeImmutable('2000-01-01');
        $party = $this->party('Example Company');
        $this->correlation->within('import:1', function () use ($party): void {
            $this->em->persist($party);
            $this->em->flush();
            $party->rename('Renamed Company');
            $this->em->flush();
        });
        $id = $party->id();

        self::assertFalse($this->trail->changedSince(Party::class, $id, $since, 'import:1'));
        self::assertTrue($this->trail->changedSince(Party::class, $id, $since, 'import:2'));

        $party->rename('Edited By Hand');
        $this->em->flush();
        self::assertTrue($this->trail->changedSince(Party::class, $id, $since, 'import:1'));
        self::assertFalse($this->trail->changedSince(Party::class, $id, new \DateTimeImmutable('+1 day'), 'import:1'));
        self::assertFalse($this->trail->changedSince(Party::class, '999999', $since, 'import:1'));

        // Several correlations can be left out at once; none at all counts every entry.
        $other = $this->party('Example Supplies');
        $this->correlation->within('import:1', function () use ($other): void {
            $this->em->persist($other);
            $this->em->flush();
        });
        $this->correlation->within('import-undo:1', function () use ($other): void {
            $other->rename('Undone');
            $this->em->flush();
        });
        $otherId = $other->id();
        self::assertFalse($this->trail->changedSince(Party::class, $otherId, $since, 'import:1', 'import-undo:1'));
        self::assertTrue($this->trail->changedSince(Party::class, $otherId, $since, 'import:1'));
        self::assertTrue($this->trail->changedSince(Party::class, $otherId, $since));
    }

    private function party(string $name): Party
    {
        return new Party(PartyKind::Company, $name, new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), '+420000000000', [PartyRole::Customer]);
    }

    /**
     * @return list<AuditEntry>
     */
    private function entries(): array
    {
        return $this->em->getRepository(AuditEntry::class)->findBy([], ['id' => 'ASC']);
    }
}
