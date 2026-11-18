<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Application\Query;

use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Tests\Invoicing\Application\InvoiceTestCase;
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
    protected Membership $owner;
    protected Membership $member;
    protected Membership $guest;

    protected function setUp(): void
    {
        parent::setUp();
        Clock::set(new MockClock('2026-11-20 10:00:00', 'UTC'));
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
