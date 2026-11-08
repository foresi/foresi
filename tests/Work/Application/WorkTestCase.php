<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Work\Application;

use App\Identity\Domain\Membership;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Party\Domain\Party;
use App\Party\Domain\PartyKind;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\Tenant;
use App\Tenancy\Domain\TenantId;
use App\Work\Application\ManageLabels;
use App\Work\Application\ManageProjects;
use App\Work\Application\ManageSections;
use App\Work\Application\ManageTasks;
use App\Work\Application\ManageWorkflow;
use App\Work\Application\WorkQueries;
use App\Work\Domain\Project;
use App\Work\Domain\ProjectStatus;
use App\Work\Domain\WorkflowTemplate;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * An organisation in Prague with an owner signed in, a member and a guest, on a
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

        $tenant = new Tenant(TenantId::generate(), 'Example Organisation', $this->clock->now());
        $tenant->changeTimezone(new \DateTimeZone('Europe/Prague'));
        $this->em->persist($tenant);
        // Switching the tenant clears the entity manager.
        $this->em->flush();
        $container->get(TenantContext::class)->switchTo($tenant->id());
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
