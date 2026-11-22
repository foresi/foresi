<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Vat\Support;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Events\Infrastructure\Dbal\EventLogTable;
use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Foresi\Party\Contract\VatPeriodLength;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Domain\VatStatus;
use Foresi\Party\Domain\VatStatusPeriod;
use Foresi\Vat\Application\VatPeriods;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * A Czech firm in Prague with a member signed in, an owner and a guest, on
 * Friday 20 November 2026 at 10:00 UTC. Its VAT statuses are written straight
 * as rows, as Party keeps them.
 */
abstract class VatTestCase extends KernelTestCase
{
    protected EntityManagerInterface $em;
    protected MockClock $clock;
    protected LegalEntity $firm;
    protected Membership $owner;
    protected Membership $member;
    protected Membership $guest;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $this->clock = new MockClock('2026-11-20 10:00:00', 'UTC');
        Clock::set($this->clock);

        $this->firm = $this->firmIn('CZ');
        $customer = new Party(PartyKind::Company, 'Customer Example s.r.o.', new RegistrationNumber('CZ', '87654321'), null, '');
        $this->em->persist($customer);
        $this->owner = $this->person('owner@example.com', Role::Owner, null);
        $this->member = $this->person('member@example.com', Role::Member, null);
        $this->guest = $this->person('guest@example.com', Role::Guest, $customer);
        $this->em->flush();
        $this->signIn($this->member);
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

    protected function periods(): VatPeriods
    {
        return self::getContainer()->get(VatPeriods::class);
    }

    /**
     * Adds a VAT status of the firm from a day, or from the start when the day is null.
     */
    protected function stretch(VatStatus $status, ?VatPeriodLength $length, ?string $from): void
    {
        $this->em->persist(new VatStatusPeriod($status, $length, null === $from ? null : new \DateTimeImmutable($from)));
        $this->em->flush();
    }

    protected function firmIn(string $country): LegalEntity
    {
        $firm = new LegalEntity('Example Sole Trader', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345678'), new Address('Example Street 1', 'Example City', '100 00', $country), VatStatus::Registered, '');
        $this->em->persist($firm);

        return $firm;
    }

    /**
     * The events of one name, oldest first, each as its subject id and facts.
     *
     * @return list<array{string, array<string, mixed>}>
     */
    protected function events(string $name): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative('SELECT '.EventLogTable::SUBJECT_ID.', '.EventLogTable::DATA.' FROM '.EventLogTable::LOG.' WHERE '.EventLogTable::NAME.' = ? ORDER BY '.EventLogTable::ID, [$name]);

        return array_map(static function (array $row): array {
            self::assertIsString($row[EventLogTable::DATA]);
            self::assertIsString($row[EventLogTable::SUBJECT_ID]);
            $data = json_decode($row[EventLogTable::DATA], true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($data);

            /* @var array<string, mixed> $data */
            return [$row[EventLogTable::SUBJECT_ID], $data];
        }, $rows);
    }

    private function person(string $email, Role $role, ?Party $customer): Membership
    {
        $user = new User($email, 'hash', 'Example Person');
        $membership = new Membership($user, $role, $customer);
        $this->em->persist($user);
        $this->em->persist($membership);

        return $membership;
    }
}
