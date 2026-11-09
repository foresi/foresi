<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Infrastructure\Doctrine;

use App\Identity\Domain\Membership;
use App\Work\Application\NotificationPreferences;
use App\Work\Domain\NotificationPreference;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineNotificationPreferences implements NotificationPreferences
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function of(Membership $membership): ?NotificationPreference
    {
        return $this->entityManager->getRepository(NotificationPreference::class)->findOneBy(['membership' => $membership]);
    }

    public function all(): array
    {
        return $this->entityManager->getRepository(NotificationPreference::class)->findAll();
    }

    public function add(NotificationPreference $preference): void
    {
        $this->entityManager->persist($preference);
    }
}
