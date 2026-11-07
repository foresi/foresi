<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Tenancy\Infrastructure\Health;

use App\Tenancy\Application\Schedule\Heartbeats;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * /health answers an uptime monitor with no user and no session: 503 while the
 * worker or the schedule is silent, 200 once both beat, numbers only. The same
 * checks run from the shell as app:health, which fails when down.
 */
final class HealthControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    private ?string $minFree = null;

    protected function setUp(): void
    {
        $this->boot();
    }

    protected function tearDown(): void
    {
        if (null !== $this->minFree) {
            $_SERVER['HEALTH_DISK_MIN_FREE_PERCENT'] = $_ENV['HEALTH_DISK_MIN_FREE_PERCENT'] = $this->minFree;
        }
        parent::tearDown();
    }

    private function boot(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    public function testASilentWorkerAnswers503WithoutSigningIn(): void
    {
        $this->client->request('GET', '/health');

        self::assertResponseStatusCodeSame(503);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertFalse($this->client->getResponse()->headers->has('Set-Cookie'), 'no session');
        self::assertSame('down', $this->body()['status']);
        self::assertStringContainsString('"worker":{"status":"down","value":null}', $this->content());
    }

    public function testARunningInstallationAnswers200WithNumbersOnly(): void
    {
        $this->beatAll();

        $this->client->request('GET', '/health');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $version = self::getContainer()->getParameter('app.version');
        // Exactly these keys, each value a number or nothing: no names, paths or tenant data.
        self::assertMatchesRegularExpression('/^\\{"status":"ok","version":"'.preg_quote($version, '/').'","checks":\\{"database":\\{"status":"ok","value":null\\},"worker":\\{"status":"ok","value":\\d+\\},"schedule":\\{"status":"ok","value":\\d+\\},"failed_messages":\\{"status":"ok","value":null\\},"disk_free_percent":\\{"status":"ok","value":\\d+\\},"system_mail":\\{"status":"ok","value":null\\}\\}\\}$/', $this->content());
    }

    public function testADegradedInstallationStillAnswers200(): void
    {
        $this->minFree = \is_string($_SERVER['HEALTH_DISK_MIN_FREE_PERCENT'] ?? null) ? $_SERVER['HEALTH_DISK_MIN_FREE_PERCENT'] : '0';
        $_SERVER['HEALTH_DISK_MIN_FREE_PERCENT'] = $_ENV['HEALTH_DISK_MIN_FREE_PERCENT'] = '101';
        self::ensureKernelShutdown();
        $this->boot();
        $this->beatAll();

        $this->client->request('GET', '/health');

        self::assertResponseIsSuccessful();
        self::assertSame('degraded', $this->body()['status']);
        self::assertStringContainsString('"disk_free_percent":{"status":"degraded"', $this->content());
        self::assertSame(0, $this->command()->execute([]), 'degraded is not down');
    }

    public function testAMonitorIsLimitedToSixtyCallsAMinute(): void
    {
        for ($i = 0; $i < 60; ++$i) {
            $this->client->request('GET', '/health');
            self::assertResponseStatusCodeSame(503);
        }
        $this->client->request('GET', '/health');
        self::assertResponseStatusCodeSame(429);

        $this->client->request('GET', '/health', server: ['REMOTE_ADDR' => '192.0.2.10']);
        self::assertResponseStatusCodeSame(503, 'another address has its own budget');
    }

    public function testTheCommandFailsWhileDown(): void
    {
        $tester = $this->command();

        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('worker', $tester->getDisplay());
        self::assertStringContainsString('Status: down', $tester->getDisplay());
    }

    public function testTheCommandSucceedsWhileRunning(): void
    {
        $this->beatAll();
        $tester = $this->command();

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Status: ok', $tester->getDisplay());
    }

    private function beatAll(): void
    {
        $heartbeats = self::getContainer()->get(Heartbeats::class);
        $heartbeats->beat(Heartbeats::WORKER);
        $heartbeats->beat(Heartbeats::SCHEDULE);
    }

    private function command(): CommandTester
    {
        $kernel = $this->client->getKernel();

        return new CommandTester(new Application($kernel)->find('app:health'));
    }

    /**
     * @return array<mixed>
     */
    private function body(): array
    {
        $body = json_decode($this->content(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }

    private function content(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }
}
