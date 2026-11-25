<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\ExchangeRate\Support;

use Foresi\Party\Application\InstanceFirm;
use Foresi\Party\Application\LegalEntities;
use Foresi\Party\Application\UnknownLegalEntity;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;

/**
 * The firm of the instance in a country, or none before the first-run setup.
 */
final readonly class OneFirm implements LegalEntities
{
    private ?LegalEntity $firm;

    public function __construct(?string $country)
    {
        $this->firm = null === $country ? null : new LegalEntity('Example Firm', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', $country), VatStatus::NotRegistered, '');
    }

    public static function in(?string $country): InstanceFirm
    {
        return new InstanceFirm(new self($country));
    }

    public function all(): array
    {
        return $this->firm instanceof LegalEntity ? [$this->firm] : [];
    }

    public function get(string $id): LegalEntity
    {
        return $this->firm ?? throw new UnknownLegalEntity();
    }

    public function lock(string $id): LegalEntity
    {
        return $this->get($id);
    }

    public function add(LegalEntity $firm): void
    {
        throw new \LogicException('The firm is given.');
    }
}
