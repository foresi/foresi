<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application\Query;

use App\Identity\Domain\Membership;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Invoicing\Domain\Invoice;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\Tenant;
use App\Tenancy\Domain\TenantId;
use App\Tests\Invoicing\Application\InvoiceTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The invoicing fixtures with an organisation that has an owner, a member and a guest,
 * the owner signed in, on 20 November 2026.
 */
abstract class InvoicingReadTestCase extends InvoiceTestCase
{
    protected Tenant $tenant;
    protected Membership $owner;
    protected Membership $member;
    protected Membership $guest;

    protected function setUp(): void
    {
        parent::setUp();
        Clock::set(new MockClock('2026-11-20 10:00:00', 'UTC'));
        $current = self::getContainer()->get(TenantContext::class)->current();
        $this->tenant = new Tenant($current, 'Example Organisation', new \DateTimeImmutable('2026-01-01'));
        $this->em->persist($this->tenant);
        $this->owner = $this->person('owner@example.com', 'Olga Owner', Role::Owner);
        $this->member = $this->person('member@example.com', 'Marek Member', Role::Member);
        $this->guest = $this->person('guest@example.com', 'Greta Guest', Role::Guest, true);
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

    protected function signOut(): void
    {
        self::getContainer()->get(TokenStorageInterface::class)->setToken(null);
    }

    /**
     * Another organisation is the active one until switchTo() brings this one back.
     */
    protected function switchTo(TenantId $tenant): void
    {
        self::getContainer()->get(TenantContext::class)->switchTo($tenant);
    }

    /**
     * Another organisation, with nothing in it and the owner as its owner, becomes the active one.
     */
    protected function switchToAnotherOrganisation(): void
    {
        $other = TenantId::generate();
        $this->switchTo($other);
        $this->em->persist(new Tenant($other, 'Other Organisation', new \DateTimeImmutable('2026-01-01')));
        $user = $this->em->find(User::class, $this->owner->user()->id());
        self::assertInstanceOf(User::class, $user);
        $this->em->persist(new Membership($user, Role::Owner));
        $this->em->flush();
    }

    protected function issued(string $issueDate = '2026-11-01', string $due = '2026-11-15', string $price = '1000.00'): Invoice
    {
        return $this->issue->issue($this->input([$this->line($price)], [
            'issueDate' => new \DateTimeImmutable($issueDate),
            'taxPointDate' => new \DateTimeImmutable($issueDate),
            'dueDate' => new \DateTimeImmutable($due),
        ]));
    }

    private function person(string $email, string $name, Role $role, bool $guest = false): Membership
    {
        $user = new User($email, 'hash', $name);
        $membership = new Membership($user, $role, $guest ? $this->customer : null);
        $this->em->persist($user);
        $this->em->persist($membership);

        return $membership;
    }
}
