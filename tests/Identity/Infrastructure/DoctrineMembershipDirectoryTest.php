<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Identity\Infrastructure;

use App\Identity\Domain\Membership;
use App\Identity\Domain\Role;
use App\Identity\Infrastructure\Doctrine\DoctrineMembershipDirectory;
use App\Instance\Application\TenantContext;
use App\Tests\Identity\Support\IdentityFixtures;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * One instance serves one customer (ADR 0035): a person has one membership,
 * and the directory answers their role in it whatever tenant is active.
 */
final class DoctrineMembershipDirectoryTest extends KernelTestCase
{
    private const string STRANGER = '019a0000-0000-7000-8000-0000000f1206';

    private EntityManagerInterface $em;
    private DoctrineMembershipDirectory $directory;
    private IdentityFixtures $fixtures;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');
        $this->directory = $container->get(DoctrineMembershipDirectory::class);
        $this->fixtures = new IdentityFixtures($this->em, $container->get(TenantContext::class));
        $this->fixtures->rebuildSchema();
    }

    public function testTheRoleIsTheOneOfTheMembership(): void
    {
        $tenant = $this->fixtures->tenant('Example Company');
        $owner = $this->fixtures->user();
        $member = $this->fixtures->user('member@example.com');
        $this->fixtures->join($owner, $tenant);
        $this->fixtures->join($member, $tenant, Role::Member);

        self::assertSame(Role::Owner, $this->directory->roleOf($owner->id()));
        self::assertSame(Role::Member, $this->directory->roleOf($member->id()));
        self::assertNull($this->directory->roleOf(self::STRANGER));
    }

    public function testADeactivatedMembershipHasNoRole(): void
    {
        $user = $this->fixtures->user();
        $this->fixtures->join($user, $this->fixtures->tenant('Example Company'), Role::Member);
        $this->em->getConnection()->executeStatement('UPDATE membership SET disabled_at = ?', ['2026-11-17 10:00:00']);

        self::assertNull($this->directory->roleOf($user->id()));
        self::assertSame([], $this->directory->organisationsOf($user->id()));
    }

    public function testAPersonHasOneMembershipOnly(): void
    {
        $user = $this->fixtures->user();
        $this->fixtures->join($user, $this->fixtures->tenant('Example Company'));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->persist(new Membership($this->em->getReference($user::class, $user->id()) ?? throw new \LogicException('The user is missing.'), Role::Member));
        $this->em->flush();
    }

    public function testTheOrganisationIsTheInstanceWithTheRole(): void
    {
        $tenant = $this->fixtures->tenant('Example Company');
        $user = $this->fixtures->user();
        $this->fixtures->join($user, $tenant, Role::Member);

        $organisations = $this->directory->organisationsOf($user->id());

        self::assertCount(1, $organisations);
        self::assertTrue($organisations[0]->id->equals($tenant));
        self::assertSame('Example Company', $organisations[0]->name);
        self::assertSame(Role::Member, $organisations[0]->role);
        self::assertSame([], $this->directory->organisationsOf(self::STRANGER));
    }
}
