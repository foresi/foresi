<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Activities\Support;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Activities\Application\ActivitiesAccess;
use Foresi\Activities\Application\ActivitiesStore;
use Foresi\Activities\Application\ActivitiesTransaction;
use Foresi\Activities\Application\ActivityReads;
use Foresi\Activities\Application\ActivityTargets;
use Foresi\Activities\Application\ActivityTimes;
use Foresi\Activities\Application\ActivityTypeWrites;
use Foresi\Activities\Application\ActivityWrites;
use Foresi\Activities\Application\Api\CreateActivityInput;
use Foresi\Activities\Application\Api\CreateActivityTypeInput;
use Foresi\Activities\Domain\Activity;
use Foresi\Activities\Domain\ActivityType;
use Foresi\Events\Infrastructure\Dbal\EventLogTable;
use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Foresi\Shared\Application\Event\EventRecorder;
use Foresi\Shared\Domain\Id;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The use cases of Activities on a fresh database, with the owner signed in
 * and contacts and deals as test doubles: Party and Sales offer the real ones.
 */
abstract class ActivitiesTestCase extends KernelTestCase
{
    protected EntityManagerInterface $em;
    protected MockClock $clock;
    protected Membership $owner;
    protected FakeActivityTarget $contacts;
    protected FakeActivityTarget $deals;
    protected string $contactId;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $this->clock = new MockClock('2026-11-22 10:00:00', 'UTC');
        Clock::set($this->clock);
        $this->owner = $this->person('owner@example.com', Role::Owner);
        $this->em->flush();
        $this->signIn($this->owner);
        $this->contacts = new FakeActivityTarget('contact');
        $this->deals = new FakeActivityTarget('deal');
        $this->contactId = Id::generate();
        $this->contacts->add($this->contactId, 'Example Client Ltd');
        self::getContainer()->set(ActivityTargets::class, new ActivityTargets([$this->contacts, $this->deals]));
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

    protected function person(string $email, Role $role): Membership
    {
        $user = new User($email, 'hash', 'Example Person');
        $membership = new Membership($user, $role);
        $this->em->persist($user);
        $this->em->persist($membership);

        return $membership;
    }

    protected function activities(?ActivitiesStore $store = null): ActivityWrites
    {
        $container = self::getContainer();

        return new ActivityWrites(
            $container->get(ActivitiesAccess::class),
            $store ?? $container->get(ActivitiesStore::class),
            $container->get(ActivitiesTransaction::class),
            $container->get(ActivityTargets::class),
            $container->get(ActivityTimes::class),
            $container->get(EventRecorder::class),
        );
    }

    protected function reads(): ActivityReads
    {
        $container = self::getContainer();

        return new ActivityReads($container->get(ActivitiesAccess::class), $container->get(ActivitiesStore::class), $container->get(ActivityTargets::class), $container->get(ActivityTimes::class));
    }

    protected function types(): ActivityTypeWrites
    {
        return self::getContainer()->get(ActivityTypeWrites::class);
    }

    protected function store(): ActivitiesStore
    {
        return self::getContainer()->get(ActivitiesStore::class);
    }

    /** A type of the seeded kind: meeting (timed, 60), call (timed, 15), note (none) or task (date). */
    protected function type(string $key): ActivityType
    {
        $existing = $this->store()->typeByKey($key);
        if ($existing instanceof ActivityType) {
            return $existing;
        }
        [$timing, $duration] = match ($key) {
            'meeting' => ['timed', 60],
            'call' => ['timed', 15],
            'note' => ['none', null],
            default => ['date', null],
        };

        return $this->types()->create(new CreateActivityTypeInput(ucfirst($key), $key, $timing, $duration));
    }

    /**
     * @param list<array{kind: string, id: string}>|null $links the contact when left out
     */
    protected function planned(string $subject, string $plannedAt = '2026-11-25T10:00:00+01:00', ?array $links = null, string $type = 'meeting'): Activity
    {
        $date = 'task' === $type;

        return $this->activities()->create(new CreateActivityInput(
            $this->type($type)->id(),
            $subject,
            plannedOn: $date ? substr($plannedAt, 0, 10) : null,
            plannedAt: $date ? null : $plannedAt,
            links: $links ?? [['kind' => 'contact', 'id' => $this->contactId]],
        ));
    }

    /**
     * @param list<array{kind: string, id: string}>|null $links the contact when left out
     */
    protected function logged(string $subject, string $doneAt, ?array $links = null): Activity
    {
        return $this->activities()->create(new CreateActivityInput($this->type('meeting')->id(), $subject, done: true, doneAt: $doneAt, links: $links ?? [['kind' => 'contact', 'id' => $this->contactId]]));
    }

    protected function reload(Activity $activity): Activity
    {
        $this->em->clear();
        $fresh = $this->store()->activity($activity->id());
        self::assertInstanceOf(Activity::class, $fresh);

        return $fresh;
    }

    /**
     * @return list<string> the JSON data of the recorded events of this name, oldest first
     */
    protected function eventData(string $name): array
    {
        $rows = $this->em->getConnection()->fetchFirstColumn('SELECT '.EventLogTable::DATA.' FROM '.EventLogTable::LOG.' WHERE '.EventLogTable::NAME.' = ? ORDER BY '.EventLogTable::ID, [$name]);

        return array_map(static fn (mixed $row): string => \is_string($row) ? $row : throw new \LogicException('No event data.'), $rows);
    }
}
