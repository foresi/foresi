<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Identity\Infrastructure;

use App\Identity\Application\UserRepository;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Doctrine\DoctrineMembershipDirectory;
use App\Identity\Infrastructure\Security\ActiveTenantSubscriber;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Tenancy\Application\NoActiveTenant;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tests\Identity\Support\IdentityFixtures;
use App\Tests\Identity\Support\InMemoryActiveTenantStore;
use App\Tests\Tenancy\Fixtures\TestNote;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class ActiveTenantSubscriberTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private TenantContext $context;
    private TokenStorage $tokens;
    private InMemoryActiveTenantStore $store;
    private ActiveTenantSubscriber $subscriber;
    private IdentityFixtures $fixtures;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get('doctrine.orm.entity_manager');
        $context = $container->get(TenantContext::class);
        $directory = $container->get(DoctrineMembershipDirectory::class);
        $users = $container->get(UserRepository::class);

        $this->em = $em;
        $this->context = $context;
        $this->tokens = new TokenStorage();
        $this->store = new InMemoryActiveTenantStore();
        $this->subscriber = new ActiveTenantSubscriber($this->tokens, $users, $directory, $this->store, $context);
        $this->fixtures = new IdentityFixtures($em, $context);
        $this->fixtures->rebuildSchema();
    }

    public function testRunsAfterTheFirewall(): void
    {
        $attributes = new \ReflectionMethod(ActiveTenantSubscriber::class, 'onRequest')->getAttributes(AsEventListener::class);

        self::assertCount(1, $attributes);
        $listener = $attributes[0]->newInstance();
        self::assertSame(KernelEvents::REQUEST, $listener->event);
        // The firewall listens at priority 8, so a lower priority runs after authentication.
        self::assertLessThan(8, $listener->priority);
    }

    public function testUnauthenticatedRequestNeverSetsATenant(): void
    {
        $a = $this->fixtures->tenant('Tenant A');
        $this->fixtures->join($this->fixtures->user(), $a);
        $this->store->store($a);

        $this->handle();

        self::assertFalse($this->context->has());
    }

    public function testSubRequestsAreIgnored(): void
    {
        $a = $this->fixtures->tenant('Tenant A');
        $user = $this->fixtures->user();
        $this->fixtures->join($user, $a);
        $this->signIn($user);

        $this->handle(HttpKernelInterface::SUB_REQUEST);

        self::assertFalse($this->context->has());
    }

    public function testSingleMembershipIsSelectedAutomatically(): void
    {
        $a = $this->fixtures->tenant('Tenant A');
        $user = $this->fixtures->user();
        $this->fixtures->join($user, $a);
        $this->signIn($user);

        $this->handle();

        self::assertTrue($this->context->current()->equals($a));
        self::assertTrue($this->store->tenant?->equals($a));
    }

    public function testTwoMembershipsWithoutChoiceLeaveNoTenant(): void
    {
        $user = $this->fixtures->user();
        $this->fixtures->join($user, $this->fixtures->tenant('Tenant A'));
        $this->fixtures->join($user, $this->fixtures->tenant('Tenant B'));
        $this->signIn($user);

        $this->handle();

        self::assertFalse($this->context->has());
        self::assertNull($this->store->tenant);
        $this->expectException(NoActiveTenant::class);
        $this->em->getRepository(TestNote::class)->findAll();
    }

    public function testStoredChoiceIsUsedWhenTheUserHasSeveralMemberships(): void
    {
        $a = $this->fixtures->tenant('Tenant A');
        $b = $this->fixtures->tenant('Tenant B');
        $user = $this->fixtures->user();
        $this->fixtures->join($user, $a);
        $this->fixtures->join($user, $b);
        $this->signIn($user);
        $this->store->store($b);

        $this->handle();

        self::assertTrue($this->context->current()->equals($b));
    }

    public function testStoredTenantWithoutMembershipIsRejected(): void
    {
        $a = $this->fixtures->tenant('Tenant A');
        $removed = $this->fixtures->tenant('Tenant B');
        $user = $this->fixtures->user();
        $this->fixtures->join($user, $a);
        $this->signIn($user);
        $this->store->store($removed);

        $this->handle();

        self::assertNull($this->store->tenant);
        self::assertFalse($this->context->has());
        $this->expectException(NoActiveTenant::class);
        $this->em->getRepository(TestNote::class)->findAll();
    }

    public function testStoredTenantOfAnotherUserIsRejected(): void
    {
        $foreign = TenantId::generate();
        $user = $this->fixtures->user();
        $this->signIn($user);
        $this->store->store($foreign);

        $this->handle();

        self::assertNull($this->store->tenant);
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
