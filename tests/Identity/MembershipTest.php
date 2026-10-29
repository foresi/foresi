<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Identity;

use App\Identity\Application\SwitchTenant;
use App\Identity\Infrastructure\Doctrine\DoctrineMembershipDirectory;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tests\Identity\Support\IdentityFixtures;
use App\Tests\Identity\Support\InMemoryActiveTenantStore;
use App\Tests\Tenancy\Fixtures\TestNote;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MembershipTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private TenantContext $context;
    private InMemoryActiveTenantStore $store;
    private SwitchTenant $switch;
    private IdentityFixtures $fixtures;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get('doctrine.orm.entity_manager');
        $context = $container->get(TenantContext::class);
        $directory = $container->get(DoctrineMembershipDirectory::class);

        $this->em = $em;
        $this->context = $context;
        $this->store = new InMemoryActiveTenantStore();
        $this->switch = new SwitchTenant($directory, $this->store, $context);
        $this->fixtures = new IdentityFixtures($em, $context);
        $this->fixtures->rebuildSchema();
    }

    public function testUserInTwoTenantsSeesOnlyActiveTenantData(): void
    {
        $a = $this->fixtures->tenant('Tenant A');
        $b = $this->fixtures->tenant('Tenant B');
        $user = $this->fixtures->user();
        $this->fixtures->join($user, $a);
        $this->fixtures->join($user, $b);
        $this->seedNote($a, 'a-note');
        $this->seedNote($b, 'b-note');

        ($this->switch)($user, $a);
        self::assertSame(['a-note'], $this->noteTexts());

        ($this->switch)($user, $b);
        self::assertSame(['b-note'], $this->noteTexts());
        self::assertTrue($this->context->current()->equals($b));
        self::assertTrue($this->store->tenant?->equals($b));
    }

    public function testSwitchToTenantWithoutMembershipThrows(): void
    {
        $a = $this->fixtures->tenant('Tenant A');
        $b = $this->fixtures->tenant('Tenant B');
        $user = $this->fixtures->user();
        $this->fixtures->join($user, $a);

        ($this->switch)($user, $a);

        try {
            ($this->switch)($user, $b);
            self::fail('Switching to a foreign tenant must throw.');
        } catch (\DomainException) {
            // The active tenant and the stored choice stay untouched.
            self::assertTrue($this->context->current()->equals($a));
            self::assertTrue($this->store->tenant?->equals($a));
        }
    }

    public function testSwitchingMigratesTheSessionOnlyOnSuccess(): void
    {
        $a = $this->fixtures->tenant('Tenant A');
        $b = $this->fixtures->tenant('Tenant B');
        $user = $this->fixtures->user();
        $this->fixtures->join($user, $a);

        ($this->switch)($user, $a);
        self::assertSame(1, $this->store->migrations);

        try {
            ($this->switch)($user, $b);
        } catch (\DomainException) {
            // Refused: nothing is stored, so the session is not migrated either.
        }
        self::assertSame(1, $this->store->migrations);
    }

    public function testAnotherUsersMembershipDoesNotCount(): void
    {
        $a = $this->fixtures->tenant('Tenant A');
        $this->fixtures->join($this->fixtures->user('other@example.com'), $a);
        $user = $this->fixtures->user();

        $this->expectException(\DomainException::class);

        ($this->switch)($user, $a);
    }

    private function seedNote(TenantId $tenant, string $text): void
    {
        $this->context->switchTo($tenant);
        $this->em->persist(new TestNote($text));
        $this->em->flush();
        $this->context->reset();
    }

    /**
     * @return list<string>
     */
    private function noteTexts(): array
    {
        return array_map(static fn (TestNote $n): string => $n->text(), $this->em->getRepository(TestNote::class)->findAll());
    }
}
