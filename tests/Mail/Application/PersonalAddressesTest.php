<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Mail\Application;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Foresi\Mail\Contract\PersonalAddresses;
use Foresi\Shared\Contract\Refused;
use Foresi\Tests\Identity\Support\IdentityFixtures;
use Foresi\Tests\Support\Mail\RecordingPurpose;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Another module's settings screen manages the person's own addresses through
 * the contract, with the rules of the address calls: one address per purpose,
 * the full address only in the answer that issues it, and someone else's
 * address unknown.
 */
final class PersonalAddressesTest extends KernelTestCase
{
    private IdentityFixtures $fixtures;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->fixtures = new IdentityFixtures(self::getContainer()->get(EntityManagerInterface::class));
        $this->fixtures->rebuildSchema();
        RecordingPurpose::reset();
        $me = $this->fixtures->user();
        $this->fixtures->join($me, Role::Member);
        $this->signIn($me);
    }

    protected function tearDown(): void
    {
        RecordingPurpose::reset();
        parent::tearDown();
    }

    public function testThePersonManagesTheirOwnAddresses(): void
    {
        $issued = $this->addresses()->create(RecordingPurpose::OWNED_KEY, ' Receipts ');

        self::assertMatchesRegularExpression('/^bank\+[a-z2-7]{26}@example\.com$/D', $issued->address);
        self::assertSame(['Receipts', RecordingPurpose::OWNED_KEY, false, 0], [$issued->view->label, $issued->view->purpose, $issued->view->isRevoked(), $issued->view->droppedToday]);
        self::assertStringNotContainsString($issued->address, $issued->view->maskedAddress);

        self::assertSame('Bills', $this->addresses()->rename($issued->view->id, 'Bills')->label);
        $replaced = $this->addresses()->replace($issued->view->id);
        self::assertNotSame($issued->address, $replaced->address);
        self::assertSame('Bills', $replaced->view->label);
        self::assertTrue($this->addresses()->revoke($replaced->view->id)->isRevoked());

        $mine = $this->addresses()->mine(RecordingPurpose::OWNED_KEY);
        self::assertSame([$issued->view->id, $replaced->view->id], array_map(static fn (\Foresi\Mail\Contract\PersonalAddress $address): string => $address->id, $mine));
        self::assertTrue($mine[0]->isRevoked() && $mine[1]->isRevoked());
    }

    public function testTheRulesOfTheAddressCallsHold(): void
    {
        $issued = $this->addresses()->create(RecordingPurpose::OWNED_KEY, 'Receipts');

        self::assertSame('address_exists', $this->reason(fn (): \Foresi\Mail\Contract\IssuedPersonalAddress => $this->addresses()->create(RecordingPurpose::OWNED_KEY, 'Again')));
        $colleague = $this->fixtures->user('colleague@example.com');
        $this->fixtures->join($colleague, Role::Member);
        $this->signIn($colleague);
        self::assertSame('not_found', $this->reason(fn (): \Foresi\Mail\Contract\PersonalAddress => $this->addresses()->rename($issued->view->id, 'Mine now')));
        self::assertSame([], $this->addresses()->mine(RecordingPurpose::OWNED_KEY));
    }

    private function reason(\Closure $call): string
    {
        try {
            $call();
        } catch (Refused $refused) {
            return $refused->reason();
        }
        self::fail('The call was not refused.');
    }

    private function addresses(): PersonalAddresses
    {
        return self::getContainer()->get(PersonalAddresses::class);
    }

    private function signIn(User $user): void
    {
        self::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken(SecurityUser::fromUser($user), 'main', ['ROLE_USER']));
    }
}
