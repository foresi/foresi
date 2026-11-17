<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Identity\Infrastructure;

use App\Identity\Domain\Membership;
use App\Identity\Domain\Role;
use App\Identity\Infrastructure\Doctrine\DoctrineMembershipDirectory;
use App\Tests\Identity\Support\IdentityFixtures;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * One instance serves one customer (ADR 0035): a person has one membership,
 * and the directory answers their role in it.
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
        $this->fixtures = new IdentityFixtures($this->em);
        $this->fixtures->rebuildSchema();
    }

    public function testTheRoleIsTheOneOfTheMembership(): void
    {
        $owner = $this->fixtures->user();
        $member = $this->fixtures->user('member@example.com');
        $this->fixtures->join($owner);
        $this->fixtures->join($member, Role::Member);

        self::assertSame(Role::Owner, $this->directory->roleOf($owner->id()));
        self::assertSame(Role::Member, $this->directory->roleOf($member->id()));
        self::assertNull($this->directory->roleOf(self::STRANGER));
    }

    public function testADeactivatedMembershipHasNoRole(): void
    {
        $user = $this->fixtures->user();
        $this->fixtures->join($user, Role::Member);
        $this->em->getConnection()->executeStatement('UPDATE membership SET disabled_at = ?', ['2026-11-17 10:00:00']);

        self::assertNull($this->directory->roleOf($user->id()));
        self::assertNull($this->directory->organisationOf($user->id()));
    }

    public function testAPersonHasOneMembershipOnly(): void
    {
        $user = $this->fixtures->user();
        $this->fixtures->join($user);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->persist(new Membership($this->em->getReference($user::class, $user->id()) ?? throw new \LogicException('The user is missing.'), Role::Member));
        $this->em->flush();
    }

    public function testTheOrganisationIsTheInstanceWithTheRole(): void
    {
        $user = $this->fixtures->user();
        $this->fixtures->join($user, Role::Member);

        $organisation = $this->directory->organisationOf($user->id());

        self::assertNotNull($organisation);
        self::assertSame('Foresi', $organisation->name);
        self::assertSame(Role::Member, $organisation->role);
        self::assertNull($this->directory->organisationOf(self::STRANGER));
    }
}
