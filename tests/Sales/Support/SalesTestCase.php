<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Sales\Support;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Events\Infrastructure\Dbal\EventLogTable;
use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Foresi\Party\Application\LinkContactPerson;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PartyRole;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Sales\Application\Api\AddDealLineInput;
use Foresi\Sales\Application\Api\CreateDealInput;
use Foresi\Sales\Application\Api\CreateDealStageInput;
use Foresi\Sales\Application\Api\CreateLostReasonInput;
use Foresi\Sales\Application\DealLineWrites;
use Foresi\Sales\Application\DealLinkWrites;
use Foresi\Sales\Application\DealReads;
use Foresi\Sales\Application\DealWrites;
use Foresi\Sales\Application\LostReasonWrites;
use Foresi\Sales\Application\SalesRefused;
use Foresi\Sales\Application\SalesStore;
use Foresi\Sales\Application\StageWrites;
use Foresi\Sales\Domain\Deal;
use Foresi\Sales\Domain\DealLine;
use Foresi\Sales\Domain\LostReason;
use Foresi\Sales\Domain\Stage;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * An organisation on a fresh database on Monday 23 November 2026 at 10:00
 * UTC, with an owner signed in, a member and a guest; the four stages and two
 * lost reasons the module seeds, made through its own writes; and a client
 * company with two contact persons, made in the address book itself.
 */
abstract class SalesTestCase extends KernelTestCase
{
    protected EntityManagerInterface $em;
    protected MockClock $clock;
    protected Membership $owner;
    protected Membership $member;
    protected Membership $guest;
    /** @var array<string, Stage> by key */
    protected array $stages = [];
    /** @var array<string, LostReason> by key */
    protected array $reasons = [];
    protected Party $client;
    protected Party $petra;
    protected Party $karel;
    private int $companies = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $this->clock = new MockClock('2026-11-23 10:00:00', 'UTC');
        Clock::set($this->clock);
        $this->owner = $this->person('owner@example.com', 'Olga Owner', Role::Owner);
        $this->member = $this->person('member@example.com', 'Marek Member', Role::Member);
        $guestContact = new Party(PartyKind::Person, 'Greta Guest', null, null, '');
        $this->em->persist($guestContact);
        $this->guest = $this->person('guest@example.com', 'Greta Guest', Role::Guest, $guestContact);
        $this->em->flush();
        $this->signIn($this->owner);

        foreach (['inquiry' => 'Inquiry', 'meeting' => 'Meeting', 'proposal' => 'Proposal', 'negotiation' => 'Negotiation'] as $key => $name) {
            $this->stages[$key] = $this->stageWrites()->create(new CreateDealStageInput($name, $key));
        }
        foreach (['price' => 'Price', 'timing' => 'Timing'] as $key => $name) {
            $this->reasons[$key] = $this->reasonWrites()->create(new CreateLostReasonInput($name, $key));
        }
        $this->client = $this->company('Alpha Example s.r.o.');
        $this->petra = $this->contactPerson($this->client, 'Petra Example');
        $this->karel = $this->contactPerson($this->client, 'Karel Example');
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

    protected function deals(): DealWrites
    {
        return self::getContainer()->get(DealWrites::class);
    }

    protected function lines(): DealLineWrites
    {
        return self::getContainer()->get(DealLineWrites::class);
    }

    protected function links(): DealLinkWrites
    {
        return self::getContainer()->get(DealLinkWrites::class);
    }

    protected function stageWrites(): StageWrites
    {
        return self::getContainer()->get(StageWrites::class);
    }

    protected function reasonWrites(): LostReasonWrites
    {
        return self::getContainer()->get(LostReasonWrites::class);
    }

    protected function reads(): DealReads
    {
        return self::getContainer()->get(DealReads::class);
    }

    protected function store(): SalesStore
    {
        return self::getContainer()->get(SalesStore::class);
    }

    /**
     * @param list<string> $contactPersonIds
     */
    protected function deal(string $title = 'Roof audit', ?Party $client = null, array $contactPersonIds = [], ?string $stageId = null): Deal
    {
        return $this->deals()->create(new CreateDealInput($title, ($client ?? $this->client)->id(), $stageId, contactPersonIds: $contactPersonIds));
    }

    /** A one-off line typed in: an audit for 12,000 CZK unless the test says otherwise. */
    protected function typedLine(Deal $deal, string $name = 'Audit', ?string $unitPrice = '12000.00', string $quantity = '1', string $currency = 'CZK'): DealLine
    {
        return $this->lines()->add(new AddDealLineInput($deal->id(), name: $name, unit: 'piece', quantity: $quantity, unitPrice: $unitPrice, currency: $currency));
    }

    /** A customer company; each one the tests make has another registration number. */
    protected function company(string $name, bool $archived = false): Party
    {
        static $numbers = ['12345679', '87654321', '25596641', '27082440'];
        $party = new Party(PartyKind::Company, $name, new RegistrationNumber('CZ', $numbers[$this->companies++ % \count($numbers)]), null, '', [PartyRole::Customer]);
        if ($archived) {
            $party->archive(new \DateTimeImmutable('2026-11-01 10:00:00'));
        }
        $this->em->persist($party);
        $this->em->flush();

        return $party;
    }

    protected function contactPerson(Party $company, string $name): Party
    {
        $person = new Party(PartyKind::Person, $name, null, null, '');
        $this->em->persist($person);
        $this->em->flush();
        self::getContainer()->get(LinkContactPerson::class)->link($company, $person, '', false);

        return $person;
    }

    protected function person(string $email, string $name, Role $role, ?Party $contact = null): Membership
    {
        $user = new User($email, 'hash', $name);
        $membership = new Membership($user, $role, $contact);
        $this->em->persist($user);
        $this->em->persist($membership);

        return $membership;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    protected function reload(string $class, string $id): object
    {
        $this->em->clear();
        $fresh = $this->em->find($class, $id);
        self::assertInstanceOf($class, $fresh);

        return $fresh;
    }

    /**
     * Expects the call to be refused for this reason.
     *
     * @param callable(): mixed $call
     */
    protected function assertRefused(string $reason, callable $call): SalesRefused
    {
        try {
            $call();
        } catch (SalesRefused $refused) {
            self::assertSame($reason, $refused->reason(), $refused->getMessage());

            return $refused;
        }
        self::fail(\sprintf('Expected the refusal "%s".', $reason));
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

            return $data;
        }, $rows);
    }
}
