<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Invoicing\Application\InvoiceBalances;
use Foresi\Invoicing\Application\ReminderSettings;
use Foresi\Invoicing\Domain\FirmReminders;
use Foresi\Party\Domain\LegalEntity;

final readonly class DoctrineReminderSettings implements ReminderSettings
{
    public function __construct(
        private EntityManagerInterface $em,
        private InvoiceBalances $balances,
    ) {
    }

    public function for(LegalEntity $firm): FirmReminders
    {
        $stored = $this->em->createQuery('SELECT r FROM '.FirmReminders::class.' r WHERE r.firm = :firm')
            ->setParameter('firm', $firm->id())
            ->getOneOrNullResult();

        return $stored instanceof FirmReminders ? $stored : FirmReminders::standard($firm);
    }

    public function save(FirmReminders $settings): void
    {
        $this->em->persist($settings);
    }

    public function canSwitchOn(): bool
    {
        return $this->balances->isAuthoritative();
    }
}
