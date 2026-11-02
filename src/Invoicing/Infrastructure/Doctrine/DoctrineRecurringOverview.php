<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Doctrine;

use App\Invoicing\Application\RecurringOverview;
use App\Invoicing\Domain\RecurringInvoice;
use App\Invoicing\Domain\RecurringInvoiceLine;
use App\Invoicing\Domain\RecurringState;
use App\Party\Domain\Party;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Queries of the ORM only, so the tenant filter covers each of them.
 */
final readonly class DoctrineRecurringOverview implements RecurringOverview
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function counts(?string $firmId): array
    {
        $query = $this->em->createQuery(
            'SELECT'
            .' SUM(CASE WHEN r.state = :active THEN 1 ELSE 0 END) AS active,'
            .' SUM(CASE WHEN r.state = :paused THEN 1 ELSE 0 END) AS paused,'
            .' SUM(CASE WHEN r.state = :ended THEN 1 ELSE 0 END) AS ended,'
            .' SUM(CASE WHEN '.RecurringFailing::dql('r').' THEN 1 ELSE 0 END) AS failing'
            .' FROM '.RecurringInvoice::class.' r'
            .(null === $firmId ? '' : ' WHERE r.firm = :firm'),
        )
            ->setParameter('active', RecurringState::Active->value)
            ->setParameter('paused', RecurringState::Paused->value)
            ->setParameter('ended', RecurringState::Ended->value);
        RecurringFailing::bind($query);
        if (null !== $firmId) {
            $query->setParameter('firm', $firmId);
        }
        /** @var array<string, int|string|null> $row */
        $row = $query->getSingleResult();
        $counts = [];
        foreach (self::TABS as $tab) {
            $counts[$tab] = (int) ($row[$tab] ?? 0);
        }

        return $counts;
    }

    public function prepare(array $templates): array
    {
        if ([] === $templates) {
            return [];
        }
        $customers = array_values(array_unique(array_map(static fn (RecurringInvoice $template): string => $template->customer()->id(), $templates)));
        // Hydrating the parties fills the proxies the templates hold.
        $this->em->createQuery('SELECT p FROM '.Party::class.' p WHERE p.id IN (:ids)')->setParameter('ids', $customers)->getResult();

        $lines = [];
        foreach ($templates as $template) {
            $lines[$template->id()] = [];
        }
        /** @var list<RecurringInvoiceLine> $found */
        $found = $this->em->createQuery('SELECT l FROM '.RecurringInvoiceLine::class.' l WHERE l.template IN (:ids) ORDER BY l.position, l.id')
            ->setParameter('ids', array_keys($lines))
            ->getResult();
        foreach ($found as $line) {
            $lines[$line->template()->id()][] = $line;
        }

        return $lines;
    }
}
