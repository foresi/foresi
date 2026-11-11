<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Party\Application\Query;

use App\Identity\Domain\Membership;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Party\Application\PartyNotAllowed;
use App\Party\Domain\Address;
use App\Party\Domain\AddressType;
use App\Party\Domain\EmailPurpose;
use App\Party\Domain\Party;
use App\Party\Domain\PartyAddress;
use App\Party\Domain\PartyEmail;
use App\Party\Domain\PartyKind;
use App\Party\Domain\PartyRole;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatId;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\Tenant;
use App\Tenancy\Domain\TenantId;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * An organisation with an owner signed in, a member and a guest, and a second
 * organisation whose contacts must never show, on a fresh schema.
 */
abstract class ContactTestCase extends KernelTestCase
{
    protected EntityManagerInterface $em;
    protected Tenant $tenant;
    protected Tenant $foreign;
    protected Membership $owner;
    protected Membership $member;
    protected Membership $guest;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $this->tenant = new Tenant(TenantId::generate(), 'Example Organisation', new \DateTimeImmutable('2026-11-10 09:00:00'));
        $this->foreign = new Tenant(TenantId::generate(), 'Foreign Organisation', new \DateTimeImmutable('2026-11-10 09:00:00'));
        $this->em->persist($this->tenant);
        $this->em->persist($this->foreign);
        $this->em->flush();
        $container->get(TenantContext::class)->switchTo($this->tenant->id());

        $client = new Party(PartyKind::Person, 'Example Client', null, null, '');
        $client->archive(new \DateTimeImmutable('2026-11-01 10:00:00'));
        $this->em->persist($client);
        $this->owner = $this->person('owner@example.com', 'Olga Owner', Role::Owner);
        $this->member = $this->person('member@example.com', 'Marek Member', Role::Member);
        $this->guest = $this->person('guest@example.com', 'Greta Guest', Role::Guest, $client);
        $this->em->flush();
        $this->signIn($this->owner);
    }

    protected function signIn(Membership $membership): void
    {
        $user = $membership->user();
        self::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken(new SecurityUser($user->id(), $user->email(), 'hash'), 'main', ['ROLE_USER']));
    }

    protected function signOut(): void
    {
        self::getContainer()->get(TokenStorageInterface::class)->setToken(null);
    }

    /**
     * Runs the read as a guest, then as nobody, and expects it to be refused both times.
     *
     * @param \Closure(): mixed $read
     */
    protected function assertRefusedForGuestAndNobody(\Closure $read): void
    {
        $this->signIn($this->guest);
        $this->assertNotAllowed($read, 'A guest reads contacts.');
        $this->signOut();
        $this->assertNotAllowed($read, 'Nobody signed in reads contacts.');
    }

    /**
     * @param \Closure(): mixed $read
     */
    private function assertNotAllowed(\Closure $read, string $message): void
    {
        try {
            $read();
        } catch (PartyNotAllowed) {
            $this->addToAssertionCount(1);

            return;
        }
        self::fail($message);
    }

    /**
     * A contact of the active tenant, with a billing address and, when given, a general e-mail address.
     *
     * @param list<PartyRole> $roles
     */
    protected function contact(
        string $name,
        PartyKind $kind = PartyKind::Company,
        array $roles = [PartyRole::Customer],
        ?string $number = '12345679',
        ?string $vatId = null,
        ?string $email = null,
        bool $archived = false,
    ): Party {
        $party = new Party($kind, $name, null === $number ? null : new RegistrationNumber('CZ', $number), null === $vatId ? null : VatId::fromString($vatId), '+420 000 000 000', $roles);
        if ($archived) {
            $party->archive(new \DateTimeImmutable('2026-11-01 10:00:00'));
        }
        $this->em->persist($party);
        $this->em->persist(new PartyAddress($party, AddressType::Billing, new Address('Example Street 1', 'Example City', '100 00', 'CZ')));
        if (null !== $email) {
            $this->em->persist(new PartyEmail($party, $email, EmailPurpose::General));
        }
        $this->em->flush();

        return $party;
    }

    /**
     * A contact of the other organisation.
     */
    protected function foreignContact(string $name): Party
    {
        $context = self::getContainer()->get(TenantContext::class);
        $context->switchTo($this->foreign->id());
        $party = $this->contact($name);
        $context->switchTo($this->tenant->id());

        return $party;
    }

    private function person(string $email, string $name, Role $role, ?Party $party = null): Membership
    {
        $user = new User($email, 'hash', $name);
        $membership = new Membership($user, $role, $party);
        $this->em->persist($user);
        $this->em->persist($membership);

        return $membership;
    }
}
