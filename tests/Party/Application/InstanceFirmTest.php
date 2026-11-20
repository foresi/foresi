<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Party\Application;

use Foresi\Party\Application\FirmAlreadyExists;
use Foresi\Party\Application\InstanceFirm;
use Foresi\Party\Application\LegalEntities;
use Foresi\Party\Application\NoFirmYet;
use Foresi\Party\Application\UnknownLegalEntity;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;
use PHPUnit\Framework\TestCase;

final class InstanceFirmTest extends TestCase
{
    public function testBeforeTheFirstRunThereIsNoFirm(): void
    {
        $firm = new InstanceFirm($this->firms());

        self::assertNull($firm->find());
        $this->expectException(NoFirmYet::class);
        $firm->get();
    }

    public function testTheFirstRunCreatesTheFirm(): void
    {
        $firms = $this->firms();
        $instance = new InstanceFirm($firms);
        $created = $this->legalEntity('Example Firm', '12345695');

        $instance->create($created);

        self::assertSame($created, $instance->get());
        self::assertSame([$created], $firms->all());
    }

    public function testASecondFirmIsRefused(): void
    {
        $firms = $this->firms($this->legalEntity('Example Firm', '12345695'));

        $this->expectException(FirmAlreadyExists::class);
        new InstanceFirm($firms)->create($this->legalEntity('Second Firm', '12345687'));
    }

    public function testWithSeveralFirmsLeftItIsTheOldestOne(): void
    {
        // Ids are time-ordered: the first one built is the oldest, whatever its name.
        $oldest = $this->legalEntity('Zeta Firm', '12345695');
        $younger = $this->legalEntity('Alpha Firm', '12345687');

        self::assertSame($oldest, new InstanceFirm($this->firms($younger, $oldest))->get());
    }

    private function legalEntity(string $name, string $number): LegalEntity
    {
        return new LegalEntity($name, new RegistrationNumber('CZ', $number), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, '');
    }

    private function firms(LegalEntity ...$stored): LegalEntities
    {
        return new class(array_values($stored)) implements LegalEntities {
            /**
             * @param list<LegalEntity> $stored
             */
            public function __construct(private array $stored)
            {
            }

            public function all(): array
            {
                return $this->stored;
            }

            public function get(string $id): LegalEntity
            {
                foreach ($this->stored as $firm) {
                    if ($firm->id() === $id) {
                        return $firm;
                    }
                }

                throw new UnknownLegalEntity();
            }

            public function lock(string $id): LegalEntity
            {
                return $this->get($id);
            }

            public function add(LegalEntity $firm): void
            {
                $this->stored[] = $firm;
            }
        };
    }
}
