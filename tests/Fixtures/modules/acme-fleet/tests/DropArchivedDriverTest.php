<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Acme\Fleet\Tests;

use Acme\Fleet\Domain\Vehicle;
use Acme\Fleet\Infrastructure\Listener\DropArchivedDriver;
use Doctrine\ORM\EntityManagerInterface;
use Foresi\Party\Contract\Event\ContactKind;
use Foresi\Party\Contract\Event\ContactArchived;
use Foresi\Shared\Contract\Event\RecordedEvent;
use Foresi\Tests\Identity\Support\IdentityFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Party's contact.archived, as the relay hands it to the module's listener.
 */
final class DropArchivedDriverTest extends KernelTestCase
{
    public function testAnArchivedContactDrivesNoVehicle(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        new IdentityFixtures($em)->rebuildSchema();
        $driver = '0192f1a4-0000-7000-8000-00000000d001';
        $em->persist(new Vehicle('0192f1a4-0000-7000-8000-000000000001', 'Delivery van', '1AB 2345', $driver, new \DateTimeImmutable()));
        $em->persist(new Vehicle('0192f1a4-0000-7000-8000-000000000002', 'Pick-up', '2CD 3456', '0192f1a4-0000-7000-8000-00000000d002', new \DateTimeImmutable()));
        $em->flush();
        $listener = self::getContainer()->get(DropArchivedDriver::class);
        self::assertSame('party.contact.archived', $listener->listensTo());

        $event = new RecordedEvent('0192f1a4-0000-7000-8000-00000000e001', 'party.contact.archived', 1, new \DateTimeImmutable(), null, new ContactArchived($driver, ContactKind::Person));
        $listener($event);
        $listener($event);
        $em->flush();
        $em->clear();

        $drivers = array_map(static fn (Vehicle $vehicle): ?string => $vehicle->driverContactId(), $em->getRepository(Vehicle::class)->findBy([], ['plate' => 'ASC']));
        self::assertSame([null, '0192f1a4-0000-7000-8000-00000000d002'], $drivers);
    }
}
