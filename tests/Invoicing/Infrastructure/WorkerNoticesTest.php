<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Infrastructure;

use Foresi\Identity\Domain\Role;
use Foresi\Instance\Application\Schedule\Heartbeats;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Owners are told on every page when the background worker stopped beating for
 * more than three minutes, with a link to the health status.
 */
final class WorkerNoticesTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private KernelBrowser $client;
    private SignedInBrowser $browser;

    protected function setUp(): void
    {
        self::mockTime('2026-11-06 10:00:00');
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
    }

    public function testAnOwnerIsToldOfAWorkerSilentForMoreThanThreeMinutes(): void
    {
        $this->seed(Role::Owner);
        self::getContainer()->get(Heartbeats::class)->beat(Heartbeats::WORKER);

        self::mockTime('2026-11-06 10:03:00');
        $this->client->request('GET', '/invoices');
        self::assertSelectorNotExists('[data-shell-notices]');

        self::mockTime('2026-11-06 10:03:01');
        $crawler = $this->client->request('GET', '/invoices');
        self::assertSelectorTextContains('[data-shell-notices]', 'The background worker is not running.');
        self::assertSelectorExists('[data-shell-notices] [role="alert"]');
        self::assertSame('/health', $crawler->filter('[data-shell-notices] a')->attr('href'));
    }

    public function testAWorkerThatNeverRanRaisesNoNotice(): void
    {
        $this->seed(Role::Owner);

        $this->client->request('GET', '/invoices');

        self::assertSelectorNotExists('[data-shell-notices]');
    }

    public function testAMemberIsNotTold(): void
    {
        $this->seed(Role::Member);
        self::getContainer()->get(Heartbeats::class)->beat(Heartbeats::WORKER);

        self::mockTime('2026-11-06 11:00:00');
        $this->client->request('GET', '/invoices');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-shell-notices]');
    }

    private function seed(Role $role): void
    {
        $this->browser->seed('en', $role);
        $this->browser->signIn();
    }
}
