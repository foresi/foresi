<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Identity\Support;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Test helper for browser tests: rebuilds the schema, seeds one user with their
 * membership and signs in through the real login form.
 */
final readonly class SignedInBrowser
{
    public IdentityFixtures $fixtures;

    public function __construct(private KernelBrowser $client, private EntityManagerInterface $em)
    {
        $this->fixtures = new IdentityFixtures($em);
        $this->fixtures->rebuildSchema();
    }

    /**
     * The user, with a membership of the given role; without one when the role is null.
     */
    public function seed(string $locale = 'en', ?Role $role = Role::Owner): User
    {
        $em = $this->em;
        $user = new User(IdentityFixtures::EMAIL, password_hash(IdentityFixtures::PASSWORD, \PASSWORD_BCRYPT, ['cost' => 4]), 'Example Company', $locale);
        $em->persist($user);
        $em->flush();
        if ($role instanceof Role) {
            $this->fixtures->join($user, $role);
        }

        return $user;
    }

    public function signIn(): void
    {
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->filter('form button[type="submit"]')->form(['_username' => IdentityFixtures::EMAIL, '_password' => IdentityFixtures::PASSWORD]));
    }

    public function em(): EntityManagerInterface
    {
        return $this->em;
    }
}
