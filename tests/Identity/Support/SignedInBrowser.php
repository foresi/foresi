<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Identity\Support;

use App\Identity\Domain\User;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Test helper for browser tests: rebuilds the schema, seeds one user with the
 * given organisations and signs in through the real login form.
 */
final readonly class SignedInBrowser
{
    public IdentityFixtures $fixtures;

    public function __construct(private KernelBrowser $client, private EntityManagerInterface $em, TenantContext $context)
    {
        $this->fixtures = new IdentityFixtures($em, $context);
        $this->fixtures->rebuildSchema();
    }

    /**
     * @param list<string> $organisations
     *
     * @return list<TenantId>
     */
    public function seed(array $organisations, string $locale = 'en'): array
    {
        $em = $this->em;
        $user = new User(IdentityFixtures::EMAIL, password_hash(IdentityFixtures::PASSWORD, \PASSWORD_BCRYPT, ['cost' => 4]), 'Example Company', $locale);
        $em->persist($user);
        $em->flush();
        $ids = [];
        foreach ($organisations as $name) {
            $id = $this->fixtures->tenant($name);
            $this->fixtures->join($user, $id);
            $ids[] = $id;
        }

        return $ids;
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
