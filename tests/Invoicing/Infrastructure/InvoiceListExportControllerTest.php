<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Infrastructure;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Identity\Domain\Role;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

final class InvoiceListExportControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private SignedInBrowser $browser;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
        Clock::set(new MockClock('2026-11-20 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testAGuestGets403(): void
    {
        $this->signedIn(Role::Guest);

        $this->client->request('POST', '/invoices/list-export', ['kind' => 'invoice', 'format' => 'csv']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testItNeedsAToken(): void
    {
        $this->signedIn();

        $this->client->request('POST', '/invoices/list-export', ['_token' => 'wrong', 'kind' => 'invoice', 'format' => 'csv']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testItIsPostOnly(): void
    {
        $this->signedIn();

        $this->client->request('GET', '/invoices/list-export');

        self::assertResponseStatusCodeSame(405);
    }

    private function signedIn(Role $role = Role::Owner, string $locale = 'en'): void
    {
        $this->browser->seed($locale, $role);
        $this->em()->flush();
        $this->browser->signIn();
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }
}
