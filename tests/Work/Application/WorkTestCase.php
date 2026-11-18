<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Application;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Foresi\Instance\Application\ConfiguredZone;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Instance\Application\ZoneFallback;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Work\Application\ManageLabels;
use Foresi\Work\Application\ManageProjects;
use Foresi\Work\Application\ManageSections;
use Foresi\Work\Application\ManageTasks;
use Foresi\Work\Application\ManageWorkflow;
use Foresi\Work\Application\WorkQueries;
use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\ProjectStatus;
use Foresi\Work\Domain\WorkflowTemplate;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * An instance in Prague with an owner signed in, a member and a guest, on a
 * fresh schema and a fixed clock.
 */
abstract class WorkTestCase extends KernelTestCase
{
    protected EntityManagerInterface $em;
    protected MockClock $clock;
    protected Membership $owner;
    protected Membership $member;
    protected Membership $guest;
    protected ManageProjects $projects;
    protected ManageSections $sections;
    protected ManageWorkflow $workflow;
    protected ManageTasks $tasks;
    protected ManageLabels $labels;
    protected WorkQueries $queries;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $this->clock = new MockClock('2026-11-10 09:00:00', 'UTC');
        Clock::set($this->clock);

        $container->set(InstanceClock::class, new InstanceClock(new ConfiguredZone('Europe/Prague'), new class implements ZoneFallback {
            public function zone(): ?\DateTimeZone
            {
                return null;
            }
        }, $this->clock));
        $client = new Party(PartyKind::Person, 'Example Client', null, null, '');
        $this->em->persist($client);
        $this->owner = $this->person('owner@example.com', 'Olga Owner', Role::Owner);
        $this->member = $this->person('member@example.com', 'Marek Member', Role::Member);
        $this->guest = $this->person('guest@example.com', 'Greta Guest', Role::Guest, $client);
        $this->em->flush();
        $this->signIn($this->owner);

        $this->projects = $container->get(ManageProjects::class);
        $this->sections = $container->get(ManageSections::class);
        $this->workflow = $container->get(ManageWorkflow::class);
        $this->tasks = $container->get(ManageTasks::class);
        $this->labels = $container->get(ManageLabels::class);
        $this->queries = $container->get(WorkQueries::class);
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

    protected function project(WorkflowTemplate $template = WorkflowTemplate::Simple): Project
    {
        return $this->projects->create('Website', null, $template);
    }

    /**
     * @return list<ProjectStatus>
     */
    protected function statuses(Project $project): array
    {
        return $this->em->getRepository(ProjectStatus::class)->findBy(['project' => $project], ['position' => 'ASC']);
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
