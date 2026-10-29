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
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
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
        $this->subscriber = new ActiveTenantSubscriber($this->tokens, $users, $directory, $this->store, $context, $container->get('router'));
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

    public function testSeveralMembershipsWithoutChoiceRedirectHtmlRequestsToTheChoicePage(): void
    {
        $this->userInTwoTenants();

        $event = $this->handle(request: $this->request('app_dashboard'));

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/organisation/choose', $response->getTargetUrl());
        self::assertFalse($this->context->has());
    }

    public function testBrowsersAcceptingAnythingAreRedirectedToo(): void
    {
        $this->userInTwoTenants();

        $event = $this->handle(request: $this->request('app_dashboard', 'text/html,application/xhtml+xml,*/*;q=0.8'));

        self::assertInstanceOf(RedirectResponse::class, $event->getResponse());
    }

    public function testJsonRequestsAreNeverRedirected(): void
    {
        $this->userInTwoTenants();

        $event = $this->handle(request: $this->request('app_dashboard', 'application/json'));

        self::assertNull($event->getResponse());
    }

    public function testSubRequestsAreNeverRedirected(): void
    {
        $this->userInTwoTenants();

        $event = $this->handle(HttpKernelInterface::SUB_REQUEST, $this->request('app_dashboard'));

        self::assertNull($event->getResponse());
    }

    #[DataProvider('exemptRoutes')]
    public function testChoicePageLoginAndLogoutAreNotRedirected(string $route): void
    {
        $this->userInTwoTenants();

        $event = $this->handle(request: $this->request($route));

        self::assertNull($event->getResponse());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function exemptRoutes(): iterable
    {
        yield 'choice page' => ['app_organisation_choose'];
        yield 'switch' => ['app_organisation_switch'];
        yield 'login' => ['app_login'];
        yield 'logout' => ['app_logout'];
        yield 'language switch' => ['app_account_language'];
    }

    #[DataProvider('assetPaths')]
    public function testAssetsAreNotRedirected(string $path): void
    {
        $this->userInTwoTenants();

        $event = $this->handle(request: $this->request('assets', path: $path));

        self::assertNull($event->getResponse());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function assetPaths(): iterable
    {
        yield 'assets' => ['/assets/app-123.css'];
        yield 'profiler' => ['/_profiler/abc'];
        yield 'toolbar' => ['/_wdt/abc'];
    }

    public function testAUserWithAStoredValidChoiceIsNotRedirected(): void
    {
        $user = $this->fixtures->user();
        $a = $this->fixtures->tenant('Tenant A');
        $this->fixtures->join($user, $a);
        $this->fixtures->join($user, $this->fixtures->tenant('Tenant B'));
        $this->signIn($user);
        $this->store->store($a);

        $event = $this->handle(request: $this->request('app_dashboard'));

        self::assertNull($event->getResponse());
        self::assertTrue($this->context->current()->equals($a));
    }

    public function testAStaleChoiceOfAUserWithSeveralMembershipsRedirectsToTheChoicePage(): void
    {
        $this->userInTwoTenants();
        $this->store->store(TenantId::generate());

        $event = $this->handle(request: $this->request('app_dashboard'));

        self::assertInstanceOf(RedirectResponse::class, $event->getResponse());
        self::assertNull($this->store->tenant);
    }

    public function testAUserWithoutMembershipsIsNotRedirected(): void
    {
        $this->signIn($this->fixtures->user());

        $event = $this->handle(request: $this->request('app_dashboard'));

        self::assertNull($event->getResponse());
        self::assertFalse($this->context->has());
    }

    private function signIn(User $user): void
    {
        $this->tokens->setToken(new UsernamePasswordToken(SecurityUser::fromUser($user), 'main', ['ROLE_USER']));
    }

    private function handle(int $type = HttpKernelInterface::MAIN_REQUEST, ?Request $request = null): RequestEvent
    {
        $event = new RequestEvent(self::$kernel ?? throw new \LogicException('No kernel.'), $request ?? new Request(), $type);
        $this->subscriber->onRequest($event);

        return $event;
    }

    private function userInTwoTenants(): void
    {
        $user = $this->fixtures->user();
        $this->fixtures->join($user, $this->fixtures->tenant('Tenant A'));
        $this->fixtures->join($user, $this->fixtures->tenant('Tenant B'));
        $this->signIn($user);
    }

    private function request(string $route, string $accept = 'text/html', string $path = '/'): Request
    {
        $request = Request::create($path, 'GET', server: ['HTTP_ACCEPT' => $accept]);
        $request->attributes->set('_route', $route);

        return $request;
    }
}
