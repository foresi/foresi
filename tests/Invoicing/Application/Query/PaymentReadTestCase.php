<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application\Query;

use App\Audit\Application\AuditActor;
use App\Identity\Application\StaffCheck;
use App\Identity\Domain\Membership;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Invoicing\Application\Query\PaymentAccess;
use App\Invoicing\Application\Query\PaymentsNotAllowed;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\Payment;
use App\Invoicing\Domain\PaymentAllocation;
use App\Invoicing\Domain\PaymentDirection;
use App\Invoicing\Domain\PaymentSource;
use App\Invoicing\Infrastructure\Doctrine\DoctrinePaymentQueries;
use App\Party\Domain\Address;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Party\Domain\PartyKind;
use App\Party\Domain\PaymentMethod;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatStatus;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\Tenant;
use App\Tenancy\Domain\TenantId;
use App\Tests\Invoicing\Application\InvoiceTestCase;
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
    protected TenantId $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $context = self::getContainer()->get(TenantContext::class);
        $this->tenantId = $context->current();
        $this->em->persist(new Tenant($this->tenantId, 'Example Organisation', new \DateTimeImmutable('2026-11-10 09:00:00')));
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
        ?LegalEntity $firm = null,
        PaymentDirection $direction = PaymentDirection::Incoming,
        PaymentMethod $method = PaymentMethod::BankTransfer,
        PaymentSource $source = PaymentSource::Manual,
        string $note = '',
        ?string $amount = null,
        bool $cancelled = false,
    ): Payment {
        $total = $amount ?? (string) array_sum(array_map(intval(...), $parts));
        $movement = PaymentSource::BankMovement === $source ? \sprintf('01963c5e-0000-7000-8000-%012d', random_int(1, 999_999)) : null;
        $payment = new Payment($firm ?? $this->firm, new \DateTimeImmutable($on), Money::of($total, Currency::CZK), $method, $source, $movement, $note, null, new \DateTimeImmutable('2026-11-10 12:00:00'), $direction);
        $this->em->persist($payment);
        foreach ($parts as $invoiceId => $part) {
            $this->em->persist(new PaymentAllocation($payment, $this->em->getReference(Invoice::class, $invoiceId) ?? throw new \LogicException('No invoice.'), Money::of($part, Currency::CZK)));
        }
        if ($cancelled) {
            $payment->cancel(new \DateTimeImmutable('2026-11-11 08:00:00'), $this->owner->user()->id());
        }
        $this->em->flush();

        return $payment;
    }

    /**
     * Another firm of the organisation.
     */
    protected function otherFirm(): LegalEntity
    {
        $firm = new LegalEntity('Other Firm s.r.o.', new RegistrationNumber('CZ', '25596641'), null, new Address('Other Street 1', 'Other City', '100 00', 'CZ'), VatStatus::NotRegistered, '');
        $this->em->persist($firm);
        $this->em->flush();

        return $firm;
    }

    /**
     * A payment of another organisation, written while it is the active one.
     */
    protected function foreignPayment(): Payment
    {
        $context = self::getContainer()->get(TenantContext::class);
        $context->switchTo(TenantId::generate());
        $firm = $this->otherFirm();
        $payment = $this->payment(firm: $firm, amount: '500');
        $context->switchTo($this->tenantId);
        // Switching the tenant cleared the entity manager.
        $this->firm = $this->em->find(LegalEntity::class, $this->firm->id()) ?? throw new \LogicException('The firm is missing.');

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
