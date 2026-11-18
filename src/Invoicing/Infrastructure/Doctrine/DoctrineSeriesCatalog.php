<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Invoicing\Application\SeriesCatalog;
use Foresi\Invoicing\Application\UnknownSeries;
use Foresi\Invoicing\Domain\DocumentKind;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Invoicing\Domain\Series;
use Foresi\Invoicing\Domain\SeriesCounter;
use Foresi\Party\Domain\LegalEntity;

final readonly class DoctrineSeriesCatalog implements SeriesCatalog
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function forFirm(LegalEntity $firm, ?DocumentKind $kind = null): array
    {
        /** @var list<Series> $series */
        $series = $this->em->createQuery('SELECT s FROM '.Series::class.' s WHERE s.firm = :firm AND s.archived = false ORDER BY s.name, s.id')
            ->setParameter('firm', $firm->id())
            ->getResult();

        return $kind instanceof DocumentKind ? array_values(array_filter($series, static fn (Series $one): bool => $one->kind() === $kind)) : $series;
    }

    public function everyForFirm(LegalEntity $firm): array
    {
        /** @var list<Series> $series */
        $series = $this->em->createQuery('SELECT s FROM '.Series::class.' s WHERE s.firm = :firm ORDER BY s.name, s.id')
            ->setParameter('firm', $firm->id())
            ->getResult();

        return $series;
    }

    public function get(string $id): Series
    {
        $series = $this->em->createQuery('SELECT s FROM '.Series::class.' s WHERE s.id = :id')->setParameter('id', $id)->getOneOrNullResult();

        return $series instanceof Series ? $series : throw new UnknownSeries();
    }

    public function defaultFor(LegalEntity $firm, DocumentKind $kind = DocumentKind::Invoice): Series
    {
        return $this->storedDefault($firm, $kind) ?? Series::standard($firm, $kind);
    }

    public function add(Series $series): void
    {
        $this->em->persist($series);
    }

    public function store(Series $series): void
    {
        $this->em->persist($series);
        $this->em->flush();
    }

    public function makeDefault(Series $series): void
    {
        $current = $this->storedDefault($series->firm(), $series->kind());
        if ($current === $series) {
            return;
        }

        $current?->clearDefault();
        $this->em->flush();
        $series->markDefault();
    }

    public function hasDocuments(Series $series): bool
    {
        $given = $this->em->createQuery('SELECT COUNT(c.id) FROM '.SeriesCounter::class.' c WHERE c.series = :series AND c.lastValue > 0')
            ->setParameter('series', $series->id())
            ->getSingleScalarResult();

        if (0 < (int) $given) {
            return true;
        }

        $invoices = $this->em->createQuery('SELECT COUNT(i.id) FROM '.Invoice::class.' i WHERE i.series = :series')
            ->setParameter('series', $series->id())
            ->getSingleScalarResult();

        return 0 < (int) $invoices;
    }

    private function storedDefault(LegalEntity $firm, DocumentKind $kind): ?Series
    {
        $series = $this->em->createQuery('SELECT s FROM '.Series::class.' s WHERE s.firm = :firm AND s.kind = :kind AND s.defaultFlag = true')
            ->setParameter('firm', $firm->id())
            ->setParameter('kind', $kind)
            ->getOneOrNullResult();

        return $series instanceof Series ? $series : null;
    }
}
