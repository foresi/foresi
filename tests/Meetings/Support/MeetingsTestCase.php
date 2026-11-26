<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Meetings\Support;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Foresi\Meetings\Application\MeetingReads;
use Foresi\Meetings\Application\MeetingVisibility;
use Foresi\Meetings\Application\MeetingWrites;
use Foresi\Meetings\Application\SeriesReads;
use Foresi\Meetings\Application\SeriesWrites;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * A firm with an owner, two members and a guest, on Thursday 26 November 2026
 * at 10:00 UTC, the owner signed in.
 */
abstract class MeetingsTestCase extends KernelTestCase
{
    protected EntityManagerInterface $em;
    protected Membership $owner;
    protected Membership $chief;
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
        Clock::set(new MockClock('2026-11-26 10:00:00', 'UTC'));
        $this->owner = $this->person('owner@example.com', 'Olivia Owner', Role::Owner);
        $this->chief = $this->person('chief@example.com', 'Charlie Chief', Role::Member);
        $this->member = $this->person('member@example.com', 'Morgan Member', Role::Member);
        $this->guest = $this->person('guest@example.com', 'Alex Guest', Role::Guest);
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
        $this->visibility()->forget();
    }

    protected function visibility(): MeetingVisibility
    {
        return self::getContainer()->get(MeetingVisibility::class);
    }

    protected function seriesWrites(): SeriesWrites
    {
        return self::getContainer()->get(SeriesWrites::class);
    }

    protected function seriesReads(): SeriesReads
    {
        return self::getContainer()->get(SeriesReads::class);
    }

    protected function meetingWrites(): MeetingWrites
    {
        return self::getContainer()->get(MeetingWrites::class);
    }

    protected function meetingReads(): MeetingReads
    {
        return self::getContainer()->get(MeetingReads::class);
    }

    private function person(string $email, string $name, Role $role): Membership
    {
        $user = new User($email, 'hash', $name);
        // A guest always represents a contact.
        $contact = null;
        if (Role::Guest === $role) {
            $contact = new Party(PartyKind::Person, $name, null, null, '');
            $this->em->persist($contact);
        }
        $membership = new Membership($user, $role, $contact);
        $this->em->persist($user);
        $this->em->persist($membership);

        return $membership;
    }
}
