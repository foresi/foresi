<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\ExchangeRate\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\ExchangeRate\Application\RateSettingStore;
use Foresi\ExchangeRate\Domain\RateSourceSetting;

final readonly class DoctrineRateSettingStore implements RateSettingStore
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function setting(): ?RateSourceSetting
    {
        return $this->entityManager->find(RateSourceSetting::class, RateSourceSetting::ID);
    }

    public function add(RateSourceSetting $setting): void
    {
        $this->entityManager->persist($setting);
    }
}
