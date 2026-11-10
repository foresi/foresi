<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\ExchangeRate\Infrastructure;

use App\ExchangeRate\Application\RateSource;
use App\ExchangeRate\Infrastructure\Doctrine\DoctrineRateStore;
use App\Tests\ExchangeRate\Support\FakeRateSource;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class FetchRatesCommandTest extends KernelTestCase
{
    private CommandTester $tester;
    private FakeRateSource $source;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($em);
        $tool->dropSchema($em->getMetadataFactory()->getAllMetadata());
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());
        $this->source = new FakeRateSource();
        self::getContainer()->set(RateSource::class, $this->source);
        $this->tester = new CommandTester(new Application($kernel)->find('app:exchange-rate:fetch'));
    }

    public function testItFetchesAndStoresTheGivenDate(): void
    {
        $status = $this->tester->execute(['date' => '2026-11-10']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Stored 2 rates valid on 2026-11-10, published on 2026-11-10.', $this->tester->getDisplay());
        self::assertSame(['2026-11-10'], $this->source->asked);
        $stored = new DoctrineRateStore(self::getContainer()->get(EntityManagerInterface::class)->getConnection());
        self::assertTrue($stored->has(new \DateTimeImmutable('2026-11-10')));
    }

    public function testItFailsWhenTheSourceIsDown(): void
    {
        $this->source->down = true;

        self::assertSame(Command::FAILURE, $this->tester->execute(['date' => '2026-11-10']));
        self::assertStringContainsString('nothing was stored', $this->tester->getDisplay());
    }

    public function testItRefusesWhatIsNotADay(): void
    {
        self::assertSame(Command::INVALID, $this->tester->execute(['date' => '2026-02-31']));
        self::assertSame(Command::INVALID, $this->tester->execute(['date' => '10.11.2026']));
        self::assertSame([], $this->source->asked);
    }

    public function testTodayIsTheDefault(): void
    {
        self::assertSame(Command::SUCCESS, $this->tester->execute([]));
        self::assertCount(1, $this->source->asked);
    }
}
