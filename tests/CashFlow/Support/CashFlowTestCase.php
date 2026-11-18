<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\CashFlow\Support;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\CashFlow\Application\CreatePlannedItemInput;
use Foresi\CashFlow\Application\PlannedItemWrites;
use Foresi\CashFlow\Domain\PlannedItem;
use Foresi\Events\Infrastructure\Dbal\EventLogTable;
use Foresi\ExchangeRate\Application\RateSource;
use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\FirmBankAccount;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Domain\Currency;
use Foresi\Tests\ExchangeRate\Support\FakeRateSource;
use Foresi\Tests\Party\Support\FirmAccounts;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * An organisation in Prague with a firm that has a CZK account, a customer, an
 * owner signed in and a guest, on Tuesday 17 November 2026 at 10:00 UTC. Rates
 * are published as the test says.
 */
abstract class CashFlowTestCase extends KernelTestCase
{
    protected const string IBAN = 'CZ6508000000192000145399';
    protected const string EURO_IBAN = 'CZ2508000000002000145401';

    protected EntityManagerInterface $em;
    protected MockClock $clock;
    protected FakeRateSource $rates;
    protected LegalEntity $firm;
    protected FirmBankAccount $account;
    protected Party $customer;
    protected Membership $owner;
    protected Membership $guest;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->rates = new FakeRateSource();
        $this->rates->publish('2026-11-16', '24.500', '21.000');
        $container->set(RateSource::class, $this->rates);
        $this->em = $container->get('doctrine.orm.entity_manager');
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $this->clock = new MockClock('2026-11-17 10:00:00', 'UTC');
        Clock::set($this->clock);

        // A Czech firm gives the instance the zone Europe/Prague.
        $this->firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::Registered, '');
        $this->em->persist($this->firm);
        $this->account = FirmAccounts::add($this->em, $this->firm, self::IBAN);
        $this->customer = new Party(PartyKind::Company, 'Customer Example s.r.o.', new RegistrationNumber('CZ', '87654321'), null, '');
        $this->em->persist($this->customer);
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

    protected function euroAccount(): FirmBankAccount
    {
        // The firm as this entity manager knows it now, after a clear too.
        $firm = $this->em->find(LegalEntity::class, $this->firm->id()) ?? throw new \LogicException('No firm.');
        $account = FirmAccounts::add($this->em, $firm, self::EURO_IBAN, Currency::EUR);
        $this->em->flush();

        return $account;
    }

    protected function writes(): PlannedItemWrites
    {
        return self::getContainer()->get(PlannedItemWrites::class);
    }

    /**
     * A planned item of the firm: rent of 10,000 CZK paid once on 2026-11-20 unless the test says otherwise.
     */
    protected function planned(
        string $on = '2026-11-20',
        string $amount = '10000.00',
        string $direction = 'out',
        string $category = 'rent',
        ?string $repeat = null,
        string $name = 'Office rent',
        string $currency = 'CZK',
        ?string $endsOn = null,
        ?int $endsAfter = null,
        int $every = 1,
        bool $lastDayOfMonth = false,
        bool $included = true,
        ?string $counterpartyId = null,
    ): PlannedItem {
        return $this->writes()->create(new CreatePlannedItemInput(
            $this->firm->id(),
            $direction,
            $name,
            $category,
            $amount,
            $on,
            $currency,
            $repeat,
            $every,
            $lastDayOfMonth,
            $endsOn,
            $endsAfter,
            $counterpartyId,
            $included,
        ));
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
        $membership = new Membership($user, $role, Role::Guest === $role ? $this->customer : null);
        $this->em->persist($user);
        $this->em->persist($membership);

        return $membership;
    }
}
