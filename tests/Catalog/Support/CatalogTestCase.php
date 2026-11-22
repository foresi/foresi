<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Catalog\Support;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Catalog\Application\CreateProductInput;
use Foresi\Catalog\Application\ProductWrites;
use Foresi\Catalog\Domain\Product;
use Foresi\Events\Infrastructure\Dbal\EventLogTable;
use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * An organisation with an owner signed in and a guest, on Sunday 22 November
 * 2026 at 10:00 UTC.
 */
abstract class CatalogTestCase extends KernelTestCase
{
    protected EntityManagerInterface $em;
    protected Membership $owner;
    protected Membership $guest;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        Clock::set(new MockClock('2026-11-22 10:00:00', 'UTC'));
        $this->owner = $this->person('owner@example.com', Role::Owner);
        $this->guest = $this->person('guest@example.com', Role::Guest);
        $this->em->flush();
        $this->signIn($this->owner);
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    protected function signIn(Membership $membership): void
    {
        $user = $membership->user();
        self::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken(new SecurityUser($user->id(), $user->email(), 'hash'), 'main', ['ROLE_USER']));
    }

    protected function writes(): ProductWrites
    {
        return self::getContainer()->get(ProductWrites::class);
    }

    /**
     * A one-off roof audit for 12,000 CZK a piece unless the test says otherwise.
     */
    protected function product(
        string $name = 'Roof audit',
        string $billingKind = 'one_off',
        string $unit = 'piece',
        ?string $unitPrice = '12000.00',
        string $currency = 'CZK',
        ?string $recurrenceUnit = null,
        ?int $interval = null,
        ?int $cycles = null,
        string $description = '',
    ): Product {
        return $this->writes()->create(new CreateProductInput($name, $billingKind, $unit, $unitPrice, $currency, $recurrenceUnit, $interval, $cycles, $description));
    }

    /**
     * @return list<array<mixed>> the data of the recorded events of this name, oldest first
     */
    protected function events(string $name): array
    {
        $rows = $this->em->getConnection()->fetchFirstColumn('SELECT '.EventLogTable::DATA.' FROM '.EventLogTable::LOG.' WHERE '.EventLogTable::NAME.' = ? ORDER BY '.EventLogTable::ID, [$name]);

        return array_map(static function (mixed $row): array {
            self::assertIsString($row);
            $data = json_decode($row, true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($data);

            /* @var array<string, mixed> $data */
            return $data;
        }, $rows);
    }

    private function person(string $email, Role $role): Membership
    {
        $user = new User($email, 'hash', 'Example Person');
        // A guest always represents a contact.
        $contact = null;
        if (Role::Guest === $role) {
            $contact = new Party(PartyKind::Person, 'Alex Example', null, null, '');
            $this->em->persist($contact);
        }
        $membership = new Membership($user, $role, $contact);
        $this->em->persist($user);
        $this->em->persist($membership);

        return $membership;
    }
}
