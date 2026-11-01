<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Infrastructure\Support;

use App\Invoicing\Application\NumberCounter;
use App\Invoicing\Application\SeriesCatalog;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\Series;
use App\Invoicing\Domain\SeriesFormat;
use App\Party\Application\TransactionalWork;
use App\Party\Domain\Address;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatStatus;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What allocate.php runs. A kernel test case only for its test container.
 */
abstract class CounterWorker extends KernelTestCase
{
    /**
     * Rebuilds the schema and creates one firm with one series.
     *
     * @return array{string, string} the series and its tenant
     */
    public static function seed(): array
    {
        self::bootKernel(['debug' => false]);
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $tenant = TenantId::generate();
        self::getContainer()->get(TenantContext::class)->switchTo($tenant);
        $firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, '', []);
        $em->persist($firm);
        $series = new Series($firm, 'Standard', SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
        self::getContainer()->get(SeriesCatalog::class)->add($series);
        $em->flush();

        return [
            $series->id(),
            $tenant->toString(),
        ];
    }

    /**
     * Waits for the moment $startAt, so every process works at the same time, and
     * allocates $count numbers, one transaction each, the way a save does. Each
     * $rollBackEvery-th transaction fails after taking its number (0: none). A lock
     * error ends the process with a failure: nothing here retries.
     *
     * @return array{list<string>, float, float} the numbers that stayed, when it began and when it ended
     */
    public static function allocate(string $seriesId, string $tenant, int $count, float $startAt, int $rollBackEvery): array
    {
        self::bootKernel(['debug' => false]);
        $container = self::getContainer();
        $container->get(TenantContext::class)->switchTo(TenantId::fromString($tenant));
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
