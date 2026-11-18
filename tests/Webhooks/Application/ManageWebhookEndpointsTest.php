<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Webhooks\Application;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Foresi\Shared\Domain\Id;
use Foresi\Tests\Identity\Support\IdentityFixtures;
use Foresi\Webhooks\Application\CreatedEndpoint;
use Foresi\Webhooks\Application\InvalidWebhookEndpoint;
use Foresi\Webhooks\Application\InvalidWebhookUrl;
use Foresi\Webhooks\Application\ManageWebhookEndpoints;
use Foresi\Webhooks\Application\Query\WebhookEndpointList;
use Foresi\Webhooks\Application\QueuedAttempt;
use Foresi\Webhooks\Application\UnknownWebhookEndpoint;
use Foresi\Webhooks\Application\WebhookNotAllowed;
use Foresi\Webhooks\Domain\DisabledReason;
use Foresi\Webhooks\Domain\WebhookEndpoint;
use Foresi\Webhooks\Domain\WebhookSecret;
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
        $fixtures = new IdentityFixtures($em);
        $fixtures->rebuildSchema();
        $this->member = $fixtures->user('member@example.com');
        $this->guest = $fixtures->user('guest@example.com');
        $fixtures->join($this->member, Role::Member);
        $fixtures->join($this->guest, Role::Guest);
        $this->signIn($this->member);
        $this->manage = $container->get(ManageWebhookEndpoints::class);
        $this->list = $container->get(WebhookEndpointList::class);
        $transport = $container->get('messenger.transport.webhooks');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();
    }

    public function testCreateMakesTheCallerTheOwnerAndQueuesAPing(): void
    {
        $created = $this->manage->create('https://hooks.example.com/foresi', '  Accounting  ', ['party.*'], 1);

        self::assertSame($this->member->id(), $created->endpoint->ownerId());
        self::assertSame('Accounting', $created->endpoint->description());
        self::assertStringStartsWith('whsec_', $created->secret->reveal());
        self::assertSame($created->secret->reveal(), $this->manage->reveal($created->endpoint->id())->reveal());
        $transport = self::getContainer()->get('messenger.transport.webhooks');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        self::assertCount(1, $transport->getSent());
    }

    public function testGuestMayNotSubscribeToContacts(): void
    {
        $this->signIn($this->guest);

        $this->expectException(WebhookNotAllowed::class);
        $this->manage->create('https://hooks.example.com/foresi', null, ['party.*'], 1);
    }

    public function testEndpointOfAModuleTheCallerCannotReadIsHidden(): void
    {
        $id = $this->manage->create('https://hooks.example.com/foresi', null, ['party.contact.archived'], 1)->endpoint->id();
        $this->signIn($this->guest);

        self::assertSame([], $this->list->slice(null, 25)->endpoints);
        $this->expectException(WebhookNotAllowed::class);
        $this->list->show($id);
    }

    public function testGuestMayNotDeleteOrRevealEither(): void
    {
        $id = $this->manage->create('https://hooks.example.com/foresi', null, ['party.*'], 1)->endpoint->id();
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
        $id = $this->manage->create('https://hooks.example.com/foresi', 'Accounting', ['party.*'], 1)->endpoint->id();

        self::assertSame('Accounting', $this->manage->update($id, version: 2)->description());
        self::assertNull($this->manage->update($id, description: '')->description());
    }

    public function testDisableIsManualAndEnableClearsIt(): void
    {
        $id = $this->manage->create('https://hooks.example.com/foresi', null, ['party.*'], 1)->endpoint->id();

        $this->manage->disable($id);
        self::assertSame(DisabledReason::Manual, $this->list->show($id)->disabledReason());
        $this->manage->enable($id);
        self::assertTrue($this->list->show($id)->isActive());
        self::assertNull($this->list->show($id)->disabledReason());
    }

    public function testUpdateWithoutActiveKeepsTheState(): void
    {
        $id = $this->manage->create('https://hooks.example.com/foresi', null, ['party.*'], 1)->endpoint->id();

        self::assertTrue($this->manage->update($id, version: 2)->isActive());
        $this->manage->disable($id);
        self::assertFalse($this->manage->update($id, version: 3)->isActive());
    }

    public function testTheLimitsThemselvesAreAccepted(): void
    {
        $description = str_repeat('č', WebhookEndpoint::DESCRIPTION_MAX_LENGTH);
        $created = $this->manage->create('https://hooks.example.com/foresi', '  '.$description.'  ', ['party.*'], WebhookEndpoint::MAX_VERSION);

        self::assertSame($description, $created->endpoint->description());
        self::assertSame(WebhookEndpoint::MAX_VERSION, $created->endpoint->version());
        self::assertNotSame($created->secret->reveal(), $this->manage->rotate($created->endpoint->id(), ManageWebhookEndpoints::MAX_KEEP_PREVIOUS_HOURS)->reveal());
    }

    public function testCreatePinsVersionOneByDefault(): void
    {
        self::assertSame(1, $this->manage->create('https://hooks.example.com/foresi', null, ['party.*'])->endpoint->version());
    }

    public function testUpdateNamesATooLongDescription(): void
    {
        $id = $this->manage->create('https://hooks.example.com/foresi', null, ['party.*'], 1)->endpoint->id();

        try {
            $this->manage->update($id, description: str_repeat('x', WebhookEndpoint::DESCRIPTION_MAX_LENGTH + 1));
            self::fail('A too long description is refused.');
        } catch (InvalidWebhookEndpoint $e) {
            self::assertSame('description', $e->parameter);
        }
    }

    public function testRotateRefusesHoursOutsideTheRange(): void
    {
        $id = $this->manage->create('https://hooks.example.com/foresi', null, ['party.*'], 1)->endpoint->id();

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
            'version' => fn (): CreatedEndpoint => $this->manage->create('https://hooks.example.com/foresi', null, ['party.*'], 0),
            'description' => fn (): CreatedEndpoint => $this->manage->create('https://hooks.example.com/foresi', str_repeat('x', 256), ['party.*'], 1),
        ] as $parameter => $call) {
            try {
                $call();
                self::fail($parameter.' is refused.');
            } catch (InvalidWebhookEndpoint $e) {
                self::assertSame($parameter, $e->parameter);
            }
        }
        $this->expectException(InvalidWebhookUrl::class);
        $this->manage->create('https://internal.example.com/foresi', null, ['party.*'], 1);
    }

    public function testUnknownEndpointIsUnknown(): void
    {
        $this->expectException(UnknownWebhookEndpoint::class);
        $this->manage->delete(Id::generate());
    }

    private function signIn(User $user): void
    {
        self::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken(new SecurityUser($user->id(), $user->email(), 'hash'), 'main', ['ROLE_USER']));
    }
}
