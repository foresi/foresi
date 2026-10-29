<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Identity\Support;

use App\Identity\Domain\Membership;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\Tenant;
use App\Tenancy\Domain\TenantId;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

/**
 * Test helper: rebuilds the schema and seeds users and memberships. The only
 * credentials are a throwaway password and the shared test identity.
 */
final readonly class IdentityFixtures
{
    public const string EMAIL = 'testing@example.com';
    public const string PASSWORD = 'throwaway-password-1';

    public function __construct(
        private EntityManagerInterface $em,
        private TenantContext $context,
    ) {
    }

    public function rebuildSchema(): void
    {
        $em = $this->em;
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    public function user(string $email = self::EMAIL): User
    {
        $user = new User($email, password_hash(self::PASSWORD, \PASSWORD_BCRYPT, ['cost' => 4]), 'Example Company');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    public function tenant(string $name): TenantId
    {
        $tenant = new Tenant(TenantId::generate(), $name, new \DateTimeImmutable());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant->id();
    }

    public function join(User $user, TenantId $tenant, Role $role = Role::Owner): void
    {
        $this->context->switchTo($tenant);
        // Switching tenant clears the entity manager, so the user is re-attached by reference.
        $this->em->persist(new Membership($this->em->getReference(User::class, $user->id()) ?? throw new \LogicException('The user is missing.'), $role));
        $this->em->flush();
        $this->context->reset();
    }
}
