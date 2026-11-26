<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Margin\Integration\Invoicing;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Foresi\Invoicing\Contract\InvoicingSlots;
use Foresi\Margin\Integration\Invoicing\RebillPanel;
use Foresi\Tests\Instance\Support\InstanceZone;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The rebill panel on the invoice screens offers its items only on a line, and none on an advance.
 */
final class RebillPanelTest extends KernelTestCase
{
    private const string LINE = '0197a1de-0000-7000-8000-00000000a002';

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        InstanceZone::set($container);
        $em = $container->get(EntityManagerInterface::class);
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $user = new User('owner@example.com', 'hash', 'Olga Owner');
        $em->persist($user);
        $em->persist(new Membership($user, Role::Owner));
        $em->flush();
        $container->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken(new SecurityUser($user->id(), $user->email(), 'hash'), 'main', ['ROLE_USER']));
    }

    public function testThePanelOffersNothingWithoutALineOrForAnotherSlot(): void
    {
        $panel = self::getContainer()->get(RebillPanel::class);

        self::assertSame([], [...$panel->items(InvoicingSlots::RECURRING_LINE_ACTIONS, [])]);
        self::assertSame([], [...$panel->items(InvoicingSlots::INVOICE_LINE_BADGES, ['lineId' => 1])]);
        self::assertSame([], [...$panel->items('invoicing.something_else', ['lineId' => self::LINE])]);
        self::assertSame([], [...$panel->items(InvoicingSlots::INVOICE_LINE_ACTIONS, ['lineId' => self::LINE, 'kind' => 'advance'])]);
        self::assertCount(1, [...$panel->items(InvoicingSlots::INVOICE_LINE_ACTIONS, ['lineId' => self::LINE, 'kind' => 'invoice'])]);
    }
}
