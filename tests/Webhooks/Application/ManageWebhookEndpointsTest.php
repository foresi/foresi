<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Webhooks\Application;

use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tests\Identity\Support\IdentityFixtures;
use App\Webhooks\Application\CreatedEndpoint;
use App\Webhooks\Application\InvalidWebhookEndpoint;
use App\Webhooks\Application\InvalidWebhookUrl;
use App\Webhooks\Application\ManageWebhookEndpoints;
use App\Webhooks\Application\Query\WebhookEndpointList;
use App\Webhooks\Application\QueuedAttempt;
use App\Webhooks\Application\UnknownWebhookEndpoint;
use App\Webhooks\Application\WebhookNotAllowed;
use App\Webhooks\Domain\DisabledReason;
use App\Webhooks\Domain\WebhookEndpoint;
use App\Webhooks\Domain\WebhookSecret;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The use cases behind REST, MCP and the screen: the person who registers an
 * endpoint owns it, and only people who may read every module it subscribes to
 * see or change it.
 */
final class ManageWebhookEndpointsTest extends KernelTestCase
{
    private ManageWebhookEndpoints $manage;
    private WebhookEndpointList $list;
    private User $member;
    private User $guest;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $context = $container->get(TenantContext::class);
        $fixtures = new IdentityFixtures($em, $context);
        $fixtures->rebuildSchema();
        $this->member = $fixtures->user('member@example.com');
        $this->guest = $fixtures->user('guest@example.com');
        $tenant = $fixtures->tenant('Example Organisation');
        $fixtures->join($this->member, $tenant, Role::Member);
        $fixtures->join($this->guest, $tenant, Role::Guest);
        $context->switchTo($tenant);
        $this->signIn($this->member);
        $this->manage = $container->get(ManageWebhookEndpoints::class);
        $this->list = $container->get(WebhookEndpointList::class);
        $transport = $container->get('messenger.transport.webhooks');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();
    }

    public function testCreateMakesTheCallerTheOwnerAndQueuesAPing(): void
    {
        $created = $this->manage->create('https://hooks.example.com/foresi', '  Accounting  ', ['invoicing.*'], 1);

        self::assertSame($this->member->id(), $created->endpoint->ownerId());
        self::assertSame('Accounting', $created->endpoint->description());
        self::assertStringStartsWith('whsec_', $created->secret->reveal());
        self::assertSame($created->secret->reveal(), $this->manage->reveal($created->endpoint->id())->reveal());
        $transport = self::getContainer()->get('messenger.transport.webhooks');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        self::assertCount(1, $transport->getSent());
    }

    public function testGuestMayNotSubscribeToInvoicing(): void
    {
        $this->signIn($this->guest);

        $this->expectException(WebhookNotAllowed::class);
        $this->manage->create('https://hooks.example.com/foresi', null, ['invoicing.*'], 1);
    }

    public function testEndpointOfAModuleTheCallerCannotReadIsHidden(): void
    {
        $id = $this->manage->create('https://hooks.example.com/foresi', null, ['invoicing.invoice.paid'], 1)->endpoint->id();
        $this->signIn($this->guest);

        self::assertSame([], $this->list->slice(null, 25)->endpoints);
        $this->expectException(WebhookNotAllowed::class);
        $this->list->show($id);
    }

    public function testGuestMayNotDeleteOrRevealEither(): void
    {
        $id = $this->manage->create('https://hooks.example.com/foresi', null, ['invoicing.*'], 1)->endpoint->id();
        $this->signIn($this->guest);

        $refused = [];
        foreach ([
            'delete' => function () use ($id): void {
                $this->manage->delete($id);
            },
            'reveal' => fn (): WebhookSecret => $this->manage->reveal($id),
            'ping' => fn (): QueuedAttempt => $this->manage->ping($id),
            'disable' => fn (): WebhookEndpoint => $this->manage->disable($id),
        ] as $name => $call) {
            try {
                $call();
            } catch (WebhookNotAllowed) {
                $refused[] = $name;
            }
        }
        self::assertSame(['delete', 'reveal', 'ping', 'disable'], $refused);
    }

    public function testDescriptionNullKeepsAndBlankClears(): void
    {
        $id = $this->manage->create('https://hooks.example.com/foresi', 'Accounting', ['invoicing.*'], 1)->endpoint->id();

        self::assertSame('Accounting', $this->manage->update($id, version: 2)->description());
        self::assertNull($this->manage->update($id, description: '')->description());
    }

    public function testDisableIsManualAndEnableClearsIt(): void
    {
        $id = $this->manage->create('https://hooks.example.com/foresi', null, ['invoicing.*'], 1)->endpoint->id();

        $this->manage->disable($id);
        self::assertSame(DisabledReason::Manual, $this->list->show($id)->disabledReason());
        $this->manage->enable($id);
        self::assertTrue($this->list->show($id)->isActive());
        self::assertNull($this->list->show($id)->disabledReason());
    }

    public function testRotateRefusesHoursOutsideTheRange(): void
    {
        $id = $this->manage->create('https://hooks.example.com/foresi', null, ['invoicing.*'], 1)->endpoint->id();

        try {
            $this->manage->rotate($id, -1);
            self::fail('Negative hours are refused.');
        } catch (InvalidWebhookEndpoint $e) {
            self::assertSame('keep_previous_hours', $e->parameter);
        }
        $this->expectException(InvalidWebhookEndpoint::class);
        $this->manage->rotate($id, 169);
    }

    public function testInvalidValuesNameTheirParameter(): void
    {
        foreach ([
            'event_types' => fn (): CreatedEndpoint => $this->manage->create('https://hooks.example.com/foresi', null, ['nonsense'], 1),
            'version' => fn (): CreatedEndpoint => $this->manage->create('https://hooks.example.com/foresi', null, ['invoicing.*'], 0),
            'description' => fn (): CreatedEndpoint => $this->manage->create('https://hooks.example.com/foresi', str_repeat('x', 256), ['invoicing.*'], 1),
        ] as $parameter => $call) {
            try {
                $call();
                self::fail($parameter.' is refused.');
            } catch (InvalidWebhookEndpoint $e) {
                self::assertSame($parameter, $e->parameter);
            }
        }
        $this->expectException(InvalidWebhookUrl::class);
        $this->manage->create('https://internal.example.com/foresi', null, ['invoicing.*'], 1);
    }

    public function testUnknownEndpointIsUnknown(): void
    {
        $this->expectException(UnknownWebhookEndpoint::class);
        $this->manage->delete(TenantId::generate()->toString());
    }

    private function signIn(User $user): void
    {
        self::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken(new SecurityUser($user->id(), $user->email(), 'hash'), 'main', ['ROLE_USER']));
    }
}
