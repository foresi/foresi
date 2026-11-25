<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Acme\Fleet\Application;

use Acme\Fleet\Contract\Event\VehicleAdded;
use Acme\Fleet\Domain\Vehicle;
use Doctrine\ORM\EntityManagerInterface;
use Foresi\Identity\Contract\StaffCheck;
use Foresi\Shared\Contract\Event\EventRecorder;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * The firm's vehicles: the use cases every surface calls, the screen, REST
 * and MCP alike. A vehicle is added in one transaction with its event.
 */
final readonly class Fleet
{
    public function __construct(
        private EntityManagerInterface $em,
        private EventRecorder $events,
        private StaffCheck $staff,
        private ClockInterface $clock,
        #[Autowire(param: 'acme_fleet.max_vehicles')]
        private int $maxVehicles,
    ) {
    }

    /**
     * @throws VehicleRefused
     */
    public function add(AddVehicleInput $input): Vehicle
    {
        if (!$this->staff->isStaff()) {
            throw VehicleRefused::notStaff();
        }
        $name = trim($input->name);
        $plate = mb_strtoupper(trim($input->plate));
        if ('' === $name) {
            throw VehicleRefused::blank('name');
        }
        if ('' === $plate) {
            throw VehicleRefused::blank('plate');
        }

        return $this->em->wrapInTransaction(function () use ($name, $plate, $input): Vehicle {
            $vehicles = $this->em->getRepository(Vehicle::class);
            if ($vehicles->count([]) >= $this->maxVehicles) {
                throw VehicleRefused::full($this->maxVehicles);
            }
            if (null !== $vehicles->findOneBy(['plate' => $plate])) {
                throw VehicleRefused::plateTaken($plate);
            }
            $vehicle = new Vehicle(Uuid::v7()->toRfc4122(), $name, $plate, $input->driverContactId, $this->clock->now());
            $this->em->persist($vehicle);
            $this->em->flush();
            $this->events->record(new VehicleAdded($vehicle->id()));

            return $vehicle;
        });
    }

    /**
     * @return list<Vehicle> in name order
     *
     * @throws VehicleRefused
     */
    public function all(): array
    {
        if (!$this->staff->isStaff()) {
            throw VehicleRefused::notStaff();
        }

        return array_values($this->em->getRepository(Vehicle::class)->findBy([], ['name' => 'ASC', 'plate' => 'ASC']));
    }

    /**
     * Takes an archived contact off every vehicle it drove. Idempotent, for a
     * listener that may be called again for the same event.
     */
    public function withoutDriver(string $contactId): void
    {
        foreach ($this->em->getRepository(Vehicle::class)->findBy(['driverContactId' => $contactId]) as $vehicle) {
            $vehicle->withoutDriver();
        }
    }

    /**
     * A vehicle as the API, the tools and the screen show it.
     *
     * @return array{id: string, name: string, plate: string, driver_contact_id: ?string, created_at: string}
     */
    public static function json(Vehicle $vehicle): array
    {
        return [
            'id' => $vehicle->id(),
            'name' => $vehicle->name(),
            'plate' => $vehicle->plate(),
            'driver_contact_id' => $vehicle->driverContactId(),
            'created_at' => $vehicle->createdAt()->format(\DATE_ATOM),
        ];
    }
}
