<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Doctrine;

use App\Invoicing\Application\SeriesCatalog;
use App\Invoicing\Application\UnknownSeries;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\Series;
use App\Invoicing\Domain\SeriesCounter;
use App\Invoicing\Domain\SeriesFormat;
use App\Party\Domain\LegalEntity;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineSeriesCatalog implements SeriesCatalog
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function forFirm(LegalEntity $firm): array
    {
        /** @var list<Series> $series */
        $series = $this->em->createQuery('SELECT s FROM '.Series::class.' s WHERE s.firm = :firm AND s.archived = false ORDER BY s.name, s.id')
            ->setParameter('firm', $firm->id())
            ->getResult();

        return $series;
    }

    public function get(string $id): Series
    {
        // A query, not find(): find() answers from the identity map without the tenant filter.
        $series = $this->em->createQuery('SELECT s FROM '.Series::class.' s WHERE s.id = :id')->setParameter('id', $id)->getOneOrNullResult();

        return $series instanceof Series ? $series : throw new UnknownSeries();
    }

    public function defaultFor(LegalEntity $firm): Series
    {
        $stored = $this->storedDefault($firm);
        if ($stored instanceof Series) {
            return $stored;
        }

        $series = new Series($firm, Series::STANDARD, SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
        $series->markDefault();

        return $series;
    }

    public function add(Series $series): void
    {
        $this->em->persist($series);
    }

    public function makeDefault(Series $series): void
    {
        $current = $this->storedDefault($series->firm());
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

        return 0 < (int) $given;
    }

    private function storedDefault(LegalEntity $firm): ?Series
    {
        $series = $this->em->createQuery('SELECT s FROM '.Series::class.' s WHERE s.firm = :firm AND s.kind = :kind AND s.defaultFlag = true')
            ->setParameter('firm', $firm->id())
            ->setParameter('kind', DocumentKind::Invoice)
            ->getOneOrNullResult();

        return $series instanceof Series ? $series : null;
    }
}
