<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Identity\Infrastructure;

use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Doctrine\DoctrineMembershipDirectory;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Identity\Infrastructure\Security\SoleTenantSubscriber;
use App\Instance\Application\SoleTenant;
use App\Instance\Application\TenantContext;
use App\Instance\Domain\TenantId;
use App\Tests\Identity\Support\IdentityFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * One instance serves one customer (ADR 0035): whoever is signed in with an
 * active membership works in the one tenant, and nobody else gets one.
 */
final class SoleTenantSubscriberTest extends KernelTestCase
{
    private TenantContext $context;
    private TokenStorage $tokens;
    private SoleTenantSubscriber $subscriber;
    private IdentityFixtures $fixtures;
    private TenantId $tenant;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->context = $container->get(TenantContext::class);
        $this->tokens = new TokenStorage();
        $this->subscriber = new SoleTenantSubscriber($this->tokens, $container->get(DoctrineMembershipDirectory::class), $container->get(SoleTenant::class), $this->context);
        $this->fixtures = new IdentityFixtures($container->get('doctrine.orm.entity_manager'), $this->context);
        $this->fixtures->rebuildSchema();
        $this->tenant = $this->fixtures->tenant('Example Company');
    }

    public function testRunsAfterTheFirewall(): void
    {
        $attributes = new \ReflectionMethod(SoleTenantSubscriber::class, 'onRequest')->getAttributes(AsEventListener::class);

        self::assertCount(1, $attributes);
        $listener = $attributes[0]->newInstance();
        self::assertSame(KernelEvents::REQUEST, $listener->event);
        // The firewall listens at priority 8, so a lower priority runs after authentication.
        self::assertLessThan(8, $listener->priority);
    }

    public function testAnAnonymousRequestGetsNoTenant(): void
    {
        $this->fixtures->join($this->fixtures->user(), $this->tenant);

        $this->handle();

        self::assertFalse($this->context->has());
    }

    public function testASubRequestIsIgnored(): void
    {
        $user = $this->fixtures->user();
        $this->fixtures->join($user, $this->tenant);
        $this->signIn($user);

        $this->handle(HttpKernelInterface::SUB_REQUEST);

        self::assertFalse($this->context->has());
    }

    public function testAMemberWorksInTheSoleTenant(): void
    {
        $user = $this->fixtures->user();
        $this->fixtures->join($user, $this->tenant, Role::Guest);
        $this->signIn($user);

        $this->handle();

        self::assertTrue($this->context->current()->equals($this->tenant));
    }

    public function testAUserWithoutAMembershipGetsNoTenant(): void
    {
        $this->signIn($this->fixtures->user());

        $this->handle();

        self::assertFalse($this->context->has());
    }

    public function testADeactivatedMembershipGetsNoTenant(): void
    {
        $user = $this->fixtures->user();
        $this->fixtures->join($user, $this->tenant, Role::Member);
        self::getContainer()->get('doctrine.orm.entity_manager')->getConnection()->executeStatement('UPDATE membership SET disabled_at = ?', ['2026-11-17 10:00:00']);
        $this->signIn($user);

        $this->handle();

        self::assertFalse($this->context->has());
    }

    private function signIn(User $user): void
    {
        $this->tokens->setToken(new UsernamePasswordToken(SecurityUser::fromUser($user), 'main', ['ROLE_USER']));
    }

    private function handle(int $type = HttpKernelInterface::MAIN_REQUEST): void
    {
        $this->subscriber->onRequest(new RequestEvent(self::$kernel ?? throw new \LogicException('No kernel.'), new Request(), $type));
    }
}
