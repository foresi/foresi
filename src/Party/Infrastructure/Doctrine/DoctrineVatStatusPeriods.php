<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Party\Application\VatStatusPeriods;
use Foresi\Party\Domain\VatStatusPeriod;

final readonly class DoctrineVatStatusPeriods implements VatStatusPeriods
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function all(): array
    {
        /** @var list<VatStatusPeriod> $periods */
        $periods = $this->em->createQuery('SELECT p FROM '.VatStatusPeriod::class.' p')->getResult();
        // In PHP: databases disagree on where an ORDER BY puts a NULL, and the undated one comes first.
        usort($periods, static fn (VatStatusPeriod $a, VatStatusPeriod $b): int => [$a->validFrom()?->format('Y-m-d') ?? '', $a->id()] <=> [$b->validFrom()?->format('Y-m-d') ?? '', $b->id()]);

        return $periods;
    }

    public function get(string $id): ?VatStatusPeriod
    {
        return $this->em->find(VatStatusPeriod::class, $id);
    }

    public function add(VatStatusPeriod $period): void
    {
        $this->em->persist($period);
    }

    public function remove(VatStatusPeriod $period): void
    {
        $this->em->remove($period);
    }
}
