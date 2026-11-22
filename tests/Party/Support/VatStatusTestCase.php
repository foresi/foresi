<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Party\Support;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Events\Infrastructure\Dbal\EventLogTable;
use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Foresi\Party\Application\AddVatStatusInput;
use Foresi\Party\Application\RemoveVatStatusInput;
use Foresi\Party\Application\VatStatusRefused;
use Foresi\Party\Application\VatStatusWrites;
use Foresi\Party\Contract\FirmVatStatuses;
use Foresi\Party\Contract\VatStretch;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Domain\VatStatus;
use Foresi\Tests\Instance\Support\InstanceZone;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * A Czech firm in Prague that is not a VAT payer yet and has a VAT ID, with no
 * VAT statuses stored, its owner signed in and a member, on Friday
 * 20 November 2026 at 10:00 UTC.
 */
abstract class VatStatusTestCase extends KernelTestCase
{
    protected EntityManagerInterface $em;
    protected MockClock $clock;
    protected LegalEntity $firm;
    protected Membership $owner;
    protected Membership $member;

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
        InstanceZone::set(self::getContainer());

        $this->firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), $this->vatId(), new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, '');
        $this->em->persist($this->firm);
        $this->owner = $this->person('owner@example.com', Role::Owner);
        $this->member = $this->person('member@example.com', Role::Member);
        $this->em->flush();
        $this->signIn($this->owner);
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    /**
     * The VAT ID of the firm of the test.
     */
    protected function vatId(): ?VatId
    {
        return VatId::fromString('CZ12345695');
    }

    protected function signIn(Membership $membership): void
    {
        $user = $membership->user();
        self::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken(new SecurityUser($user->id(), $user->email(), 'hash'), 'main', ['ROLE_USER']));
    }

    protected function writes(): VatStatusWrites
    {
        return self::getContainer()->get(VatStatusWrites::class);
    }

    protected function reads(): FirmVatStatuses
    {
        return self::getContainer()->get(FirmVatStatuses::class);
    }

    protected function add(string $status, ?string $length, string $from): VatStretch
    {
        return $this->writes()->add(new AddVatStatusInput($status, $from, $length));
    }

    protected function remove(string $id): string
    {
        return $this->writes()->remove(new RemoveVatStatusInput($id));
    }

    /**
     * @param \Closure(): mixed $work
     */
    protected function refused(\Closure $work): VatStatusRefused
    {
        try {
            $work();
        } catch (VatStatusRefused $refused) {
            return $refused;
        }

        self::fail('The change was not refused.');
    }

    /**
     * The firm's status of today as stored, read back from the database.
     */
    protected function storedStatus(): VatStatus
    {
        $this->em->clear();
        $firm = $this->em->find(LegalEntity::class, $this->firm->id());
        self::assertInstanceOf(LegalEntity::class, $firm);

        return $firm->vatStatus();
    }

    /**
     * @return list<array{string, string|null, string|null}> status, period length and first day of every stretch, oldest first
     */
    protected function stretches(): array
    {
        $this->em->clear();

        return array_map(static fn (VatStretch $stretch): array => [$stretch->status->value, $stretch->periodLength?->value, $stretch->validFrom?->format('Y-m-d')], $this->reads()->stretches());
    }

    protected function storedCount(): int
    {
        return \count($this->em->getConnection()->fetchFirstColumn('SELECT id FROM party_vat_status_period'));
    }

    /**
     * @return list<array<mixed>> the facts of the recorded events of this name, oldest first
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
        $membership = new Membership($user, $role);
        $this->em->persist($user);
        $this->em->persist($membership);

        return $membership;
    }
}
