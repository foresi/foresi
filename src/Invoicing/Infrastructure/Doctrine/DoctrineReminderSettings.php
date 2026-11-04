<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Doctrine;

use App\Invoicing\Application\InvoiceBalances;
use App\Invoicing\Application\ReminderSettings;
use App\Invoicing\Domain\FirmReminders;
use App\Party\Domain\LegalEntity;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class DoctrineReminderSettings implements ReminderSettings
{
    public function __construct(
        private EntityManagerInterface $em,
        private InvoiceBalances $balances,
        #[Autowire(param: 'app.reminders.allow_without_payments')]
        private bool $allowWithoutPayments,
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
        return $this->allowWithoutPayments || $this->balances->isAuthoritative();
    }
}
