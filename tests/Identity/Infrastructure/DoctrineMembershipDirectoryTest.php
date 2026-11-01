<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Identity\Infrastructure;

use App\Identity\Application\Organisation;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Doctrine\DoctrineMembershipDirectory;
use App\Tenancy\Application\NoActiveTenant;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Infrastructure\Doctrine\TenantFilter;
use App\Tests\Identity\Support\IdentityFixtures;
use App\Tests\Tenancy\Fixtures\TestNote;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineMembershipDirectoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private TenantContext $context;
    private DoctrineMembershipDirectory $directory;
    private IdentityFixtures $fixtures;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get('doctrine.orm.entity_manager');
        $context = $container->get(TenantContext::class);
        $directory = $container->get(DoctrineMembershipDirectory::class);

        $this->em = $em;
        $this->context = $context;
        $this->directory = $directory;
        $this->fixtures = new IdentityFixtures($em, $context);
        $this->fixtures->rebuildSchema();
    }

    public function testListsMembershipsAcrossTenantsWhateverTenantIsActive(): void
    {
        [$user, $a, $b] = $this->userInTwoTenants();
        $this->fixtures->join($this->fixtures->user('other@example.com'), $this->fixtures->tenant('Tenant C'));

        $this->context->switchTo($a);
        $ids = $this->directory->tenantIdsOf($user);

        self::assertCount(2, $ids);
        self::assertTrue($ids[0]->equals($a));
        self::assertTrue($ids[1]->equals($b));
    }

    public function testListsMembershipsWithoutActiveTenantAndStaysFailClosed(): void
    {
        [$user] = $this->userInTwoTenants();

        self::assertCount(2, $this->directory->tenantIdsOf($user));

        self::assertTrue($this->em->getFilters()->isEnabled(TenantFilter::NAME));
        $this->expectException(NoActiveTenant::class);
        $this->em->getRepository(TestNote::class)->findAll();
    }

    public function testActiveTenantIsRestoredAfterTheLookup(): void
    {
        [$user, $a, $b] = $this->userInTwoTenants();
        $this->seedNote($a, 'a-note');
        $this->seedNote($b, 'b-note');

        $this->context->switchTo($a);
        $this->directory->tenantIdsOf($user);

        self::assertTrue($this->em->getFilters()->isEnabled(TenantFilter::NAME));
        self::assertTrue($this->context->current()->equals($a));
        self::assertSame(['a-note'], array_map(static fn (TestNote $n): string => $n->text(), $this->em->getRepository(TestNote::class)->findAll()));
    }

    public function testManagedEntitiesAndPendingChangesSurviveTheLookup(): void
    {
        [$user, $a] = $this->userInTwoTenants();
        $this->seedNote($a, 'a-note');
        $this->context->switchTo($a);
        $managed = $this->em->getRepository(TestNote::class)->findOneBy(['text' => 'a-note']);
        self::assertInstanceOf(TestNote::class, $managed);
        $this->em->persist(new TestNote('pending'));

        $this->directory->tenantIdsOf($user);

        self::assertTrue($this->em->contains($managed));
        self::assertTrue($this->em->getUnitOfWork()->hasPendingInsertions());
        $this->em->flush();
        $texts = array_map(static fn (TestNote $n): string => $n->text(), $this->em->getRepository(TestNote::class)->findAll());
        sort($texts);
        self::assertSame(['a-note', 'pending'], $texts);
        self::assertSame($managed, $this->em->getRepository(TestNote::class)->findOneBy(['text' => 'a-note']));
    }

    public function testFilterIsRestoredEvenWhenTheLookupThrows(): void
    {
        [, $a] = $this->userInTwoTenants();
        $this->seedNote($a, 'a-note');
        $this->context->switchTo($a);

        try {
            $this->directory->tenantIdsOf(new User('ghost@example.com', 'hash', 'Ghost'));
            self::fail('Looking up an unsaved user must throw.');
        } catch (\Throwable) {
            self::assertTrue($this->em->getFilters()->isEnabled(TenantFilter::NAME));
            self::assertTrue($this->context->current()->equals($a));
            self::assertCount(1, $this->em->getRepository(TestNote::class)->findAll());
        }
    }

    public function testFilterIsRestoredWhenThrowingWithoutActiveTenant(): void
    {
        try {
            $this->directory->tenantIdsOf(new User('ghost@example.com', 'hash', 'Ghost'));
            self::fail('Looking up an unsaved user must throw.');
        } catch (\Throwable) {
            self::assertTrue($this->em->getFilters()->isEnabled(TenantFilter::NAME));
        }

        $this->expectException(NoActiveTenant::class);
        $this->em->getRepository(TestNote::class)->findAll();
    }

    public function testOrganisationsOfListsNamesAndRolesOfTheUsersTenantsOnly(): void
    {
        [$user, $a, $b] = $this->userInTwoTenants();
        $this->fixtures->join($this->fixtures->user('other@example.com'), $this->fixtures->tenant('Tenant C'));

        $this->context->switchTo($a);
        $organisations = $this->directory->organisationsOf($user->id());

        self::assertCount(2, $organisations);
        self::assertSame(['Tenant A', 'Tenant B'], array_map(static fn (Organisation $o): string => $o->name, $organisations));
        self::assertTrue($organisations[0]->id->equals($a));
        self::assertTrue($organisations[1]->id->equals($b));
        self::assertSame(Role::Owner, $organisations[0]->role);
    }

    public function testOrganisationsOfWorksWithoutActiveTenantAndReturnsNothingForStrangers(): void
    {
        [$user] = $this->userInTwoTenants();

        self::assertCount(2, $this->directory->organisationsOf($user->id()));
        self::assertSame([], $this->directory->organisationsOf('019a0000-0000-7000-8000-0000000f1206'));
    }

    public function testOrganisationsOfIsScalarOnlyAndRestoresTheFilter(): void
    {
        [$user, $a] = $this->userInTwoTenants();
        $this->seedNote($a, 'a-note');
        $this->context->switchTo($a);
        $managedBefore = $this->em->getUnitOfWork()->size();

        $this->directory->organisationsOf($user->id());

        // No Membership or Tenant entity became managed by the cross-tenant read.
        self::assertSame($managedBefore, $this->em->getUnitOfWork()->size());
        self::assertTrue($this->em->getFilters()->isEnabled(TenantFilter::NAME));
        self::assertTrue($this->context->current()->equals($a));
        self::assertCount(1, $this->em->getRepository(TestNote::class)->findAll());
    }

    public function testOrganisationsOfStaysFailClosedWithoutActiveTenant(): void
    {
        [$user] = $this->userInTwoTenants();

        $this->directory->organisationsOf($user->id());

        $this->expectException(NoActiveTenant::class);
        $this->em->getRepository(TestNote::class)->findAll();
    }

    /**
     * @return array{User, TenantId, TenantId}
     */
    private function userInTwoTenants(): array
    {
        $a = $this->fixtures->tenant('Tenant A');
        $b = $this->fixtures->tenant('Tenant B');
        $user = $this->fixtures->user();
        $this->fixtures->join($user, $a);
        $this->fixtures->join($user, $b);

        return [$user, $a, $b];
    }

    private function seedNote(TenantId $tenant, string $text): void
    {
        $this->context->switchTo($tenant);
        $this->em->persist(new TestNote($text));
        $this->em->flush();
        $this->context->reset();
    }
}
