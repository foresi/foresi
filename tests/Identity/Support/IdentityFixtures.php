<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Identity\Support;

use App\Identity\Domain\Membership;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Party\Domain\Party;
use App\Party\Domain\PartyKind;
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

    public function __construct(private EntityManagerInterface $em)
    {
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

    /**
     * Gives the user their one membership in the instance.
     */
    public function join(User $user, Role $role = Role::Owner): void
    {
        // A guest always represents a contact.
        $party = null;
        if (Role::Guest === $role) {
            $party = new Party(PartyKind::Person, 'Alex Example', null, null, '');
            $this->em->persist($party);
        }
        // By reference, as a test may have cleared the entity manager since the user was made.
        $this->em->persist(new Membership($this->em->getReference(User::class, $user->id()) ?? throw new \LogicException('The user is missing.'), $role, $party));
        $this->em->flush();
    }
}
