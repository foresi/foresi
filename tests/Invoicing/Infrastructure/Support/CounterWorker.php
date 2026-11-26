<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Infrastructure\Support;

use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Invoicing\Application\NumberCounter;
use Foresi\Invoicing\Application\SeriesCatalog;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Domain\Series;
use Foresi\Invoicing\Domain\SeriesFormat;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Application\TransactionalWork;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What allocate.php runs. A kernel test case only for its test container.
 */
abstract class CounterWorker extends KernelTestCase
{
    /**
     * Rebuilds the schema and creates one firm with one series.
     *
     * @return string the series id
     */
    public static function seed(): string
    {
        self::bootKernel(['debug' => false]);
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, '');
        $em->persist($firm);
        $series = new Series('Standard', SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
        self::getContainer()->get(SeriesCatalog::class)->add($series);
        $em->flush();

        return $series->id();
    }

    /**
     * Waits for the moment $startAt, so every process works at the same time, and
     * allocates $count numbers, one transaction each, the way a save does. Each
     * $rollBackEvery-th transaction fails after taking its number (0: none). A lock
     * error ends the process with a failure: nothing here retries.
     *
     * @return array{list<string>, float, float} the numbers that stayed, when it began and when it ended
     */
    public static function allocate(string $seriesId, int $count, float $startAt, int $rollBackEvery): array
    {
        self::bootKernel(['debug' => false]);
        $container = self::getContainer();
        $catalog = $container->get(SeriesCatalog::class);
        $counter = $container->get(NumberCounter::class);
        $work = $container->get(TransactionalWork::class);
        $series = $catalog->get($seriesId);
        $date = new \DateTimeImmutable('2026-10-31');

        usleep(max(0, (int) (($startAt - microtime(true)) * 1_000_000)));

        $began = microtime(true);
        $numbers = [];
        for ($i = 1; $i <= $count; ++$i) {
            try {
                $numbers[] = $work->run(static function () use ($counter, $series, $date, $i, $rollBackEvery): string {
                    $number = $counter->next($series, $date);
                    if ($rollBackEvery > 0 && 0 === $i % $rollBackEvery) {
                        throw new \DomainException('Rolled back on purpose.');
                    }

                    return $number;
                });
            } catch (\DomainException) {
                // A failed unit of work detaches what was loaded.
                $series = $catalog->get($seriesId);
            }
        }

        return [
            $numbers,
            $began,
            microtime(true),
        ];
    }
}
