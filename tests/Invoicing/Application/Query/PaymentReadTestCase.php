<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Application\Query;

use Foresi\Audit\Application\AuditActor;
use Foresi\Identity\Contract\StaffCheck;
use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Foresi\Invoicing\Application\Query\PaymentAccess;
use Foresi\Invoicing\Application\Query\PaymentsNotAllowed;
use Foresi\Invoicing\Contract\PaymentMethod;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Invoicing\Domain\Payment;
use Foresi\Invoicing\Domain\PaymentAllocation;
use Foresi\Invoicing\Domain\PaymentDirection;
use Foresi\Invoicing\Domain\PaymentSource;
use Foresi\Invoicing\Infrastructure\Doctrine\DoctrinePaymentQueries;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\Invoicing\Application\InvoiceTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The firm, series and customer of every invoicing test, with an owner signed in, a
 * member and a guest of the organisation, and payments written straight to the
 * store so each test says the day, the way and the invoices of its own.
 */
abstract class PaymentReadTestCase extends InvoiceTestCase
{
    protected Membership $owner;
    protected Membership $member;
    protected Membership $guest;

    protected function setUp(): void
    {
        parent::setUp();
        $client = new Party(PartyKind::Person, 'Example Client', null, null, '');
        $this->em->persist($client);
        $this->owner = $this->person('owner@example.com', 'Olga Owner', Role::Owner);
        $this->member = $this->person('member@example.com', 'Marek Member', Role::Member);
        $this->guest = $this->person('guest@example.com', 'Greta Guest', Role::Guest, $client);
        $this->em->flush();
        $this->signIn($this->owner);
    }

    /**
     * Built by hand until a controller of the API uses it, as the container drops what nothing uses.
     */
    protected function access(): PaymentAccess
    {
        $container = self::getContainer();

        return new PaymentAccess($container->get(StaffCheck::class), $container->get(AuditActor::class));
    }

    protected function queries(): DoctrinePaymentQueries
    {
        return new DoctrinePaymentQueries($this->em);
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
        $this->assertNotAllowed($read, 'A guest reads payments.');
        $this->signOut();
        $this->assertNotAllowed($read, 'Nobody signed in reads payments.');
    }

    /**
     * @param \Closure(): mixed $read
     */
    private function assertNotAllowed(\Closure $read, string $message): void
    {
        try {
            $read();
        } catch (PaymentsNotAllowed) {
            $this->addToAssertionCount(1);

            return;
        }
        self::fail($message);
    }

    protected function invoice(): Invoice
    {
        $invoice = $this->issue->issue($this->input());
        $this->em->flush();

        return $invoice;
    }

    /**
     * A payment of the firm with its allocations, as a recorded one would be.
     *
     * @param array<string, string> $parts the amount in whole currency units by invoice id; the payment is their sum
     */
    protected function payment(
        string $on = '2026-11-10',
        array $parts = [],
        PaymentDirection $direction = PaymentDirection::Incoming,
        PaymentMethod $method = PaymentMethod::BankTransfer,
        PaymentSource $source = PaymentSource::Manual,
        string $note = '',
        ?string $amount = null,
        bool $cancelled = false,
    ): Payment {
        $total = $amount ?? (string) array_sum(array_map(intval(...), $parts));
        $movement = PaymentSource::BankMovement === $source ? \sprintf('01963c5e-0000-7000-8000-%012d', random_int(1, 999_999)) : null;
        $payment = new Payment(new \DateTimeImmutable($on), Money::of($total, Currency::of('CZK')), $method, $source, $movement, $note, null, new \DateTimeImmutable('2026-11-10 12:00:00'), $direction);
        $this->em->persist($payment);
        foreach ($parts as $invoiceId => $part) {
            $this->em->persist(new PaymentAllocation($payment, $this->em->getReference(Invoice::class, $invoiceId) ?? throw new \LogicException('No invoice.'), Money::of($part, Currency::of('CZK'))));
        }
        if ($cancelled) {
            $payment->cancel(new \DateTimeImmutable('2026-11-11 08:00:00'), $this->owner->user()->id());
        }
        $this->em->flush();

        return $payment;
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
