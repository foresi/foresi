<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Identity\Domain\Membership;
use Foresi\Work\Application\NotificationPreferences;
use Foresi\Work\Domain\NotificationPreference;

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
