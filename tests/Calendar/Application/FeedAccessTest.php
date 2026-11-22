<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Calendar\Application;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Calendar\Application\CalendarRefused;
use Foresi\Calendar\Application\CreateFeedInput;
use Foresi\Calendar\Application\FeedReads;
use Foresi\Calendar\Application\FeedWrites;
use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Tests\Identity\Support\IdentityFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The use cases refuse anyone but the staff, whatever surface asks: no one
 * signed in, and a guest signed in (ADR 0041).
 */
final class FeedAccessTest extends KernelTestCase
{
    private IdentityFixtures $fixtures;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->fixtures = new IdentityFixtures(self::getContainer()->get(EntityManagerInterface::class));
        $this->fixtures->rebuildSchema();
    }

    public function testNoOneSignedInIsRefused(): void
    {
        $this->assertRefused();
    }

    public function testAGuestIsRefused(): void
    {
        $user = $this->fixtures->user();
        $this->fixtures->join($user, Role::Member);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $contact = new Party(PartyKind::Company, 'Example Guest a.s.', new RegistrationNumber('CZ', '87654320'), VatId::fromString('CZ87654320'), '+420 000 000 000');
        $em->persist($contact);
        $membership = $em->getRepository(Membership::class)->findOneBy(['user' => $user->id()]);
        self::assertInstanceOf(Membership::class, $membership);
        $membership->changeRole(Role::Guest, $contact);
        $em->flush();
        $this->signIn($user);

        $this->assertRefused();
    }

    public function testAMemberIsServed(): void
    {
        $user = $this->fixtures->user();
        $this->fixtures->join($user, Role::Member);
        $this->signIn($user);

        $issued = self::getContainer()->get(FeedWrites::class)->create(new CreateFeedInput('Phone'));

        self::assertSame([$issued->feed], self::getContainer()->get(FeedReads::class)->mine());
    }

    private function signIn(User $user): void
    {
        self::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken(SecurityUser::fromUser($user), 'main', ['ROLE_USER']));
    }

    private function assertRefused(): void
    {
        foreach ([
            static fn () => self::getContainer()->get(FeedReads::class)->mine(),
            static fn () => self::getContainer()->get(FeedWrites::class)->create(new CreateFeedInput('Phone')),
        ] as $call) {
            try {
                $call();
                self::fail('Not refused.');
            } catch (CalendarRefused $refused) {
                self::assertSame('forbidden', $refused->reason());
            }
        }
    }
}
