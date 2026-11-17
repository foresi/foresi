<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Instance\Infrastructure\Support;

use App\Instance\Application\Schedule\Claim;
use App\Instance\Application\Schedule\ScheduledJobRuns;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * One worker process of ClaimRaceTest. A kernel test case only to reach the
 * test container; it holds no tests.
 */
abstract class ClaimWorker extends KernelTestCase
{
    public static function seed(): void
    {
        self::bootKernel(['debug' => false]);
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    /**
     * Claims and finishes one period per round, every process starting each round
     * at the same moment; a finished period frees the job for the next round.
     *
     * @return list<int> 1 for a round this process claimed, else 0
     */
    public static function claim(float $startAt, int $rounds): array
    {
        self::bootKernel(['debug' => false]);
        $container = self::getContainer();
        $runs = $container->get(ScheduledJobRuns::class);

        $results = [];
        for ($round = 0; $round < $rounds; ++$round) {
            time_sleep_until($startAt + $round * 0.25);
            $claim = $runs->claim('race', \sprintf('p%04d', $round));
            if ($claim instanceof Claim) {
                $runs->finish($claim);
            }
            $results[] = $claim instanceof Claim ? 1 : 0;
        }

        return $results;
    }
}
