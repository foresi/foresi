<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Purchasing\Functional;

use Foresi\Identity\Domain\Role;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Ui\Slot\SlotRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Settings → Received documents: a plain host for the sections other modules add, reachable from the settings
 * navigation, for the owners and members only.
 */
final class ReceivedDocumentsSettingsTest extends WebTestCase
{
    private const string URL = '/settings/received-documents';

    private KernelBrowser $client;

    public function testThePageIsInTheSettingsNavigation(): void
    {
        $this->signIn(Role::Owner);

        $page = $this->client->request('GET', self::URL);

        self::assertResponseIsSuccessful();
        self::assertSame('Received documents', trim($page->filter('h1')->first()->text()));
        self::assertGreaterThan(0, $page->filter('nav a[href="'.self::URL.'"][aria-current="page"]')->count());
        self::assertCount(0, $page->filter('nav a[href="/purchasing"][aria-current="page"]'));
    }

    public function testThePageRendersWithNoSectionProvider(): void
    {
        $this->signIn(Role::Owner);
        self::getContainer()->set(SlotRegistry::class, new SlotRegistry([]));

        $page = $this->client->request('GET', self::URL);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $page->filter('[data-purchasing-settings-empty]'));
        self::assertCount(0, $page->filter('[data-purchasing-settings-section]'));
    }

    public function testAGuestIsRefused(): void
    {
        $this->signIn(null);

        $this->client->request('GET', self::URL);

        self::assertResponseStatusCodeSame(403);
    }

    private function signIn(?Role $role): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
        $browser->seed('en', $role);
        $browser->signIn();
    }
}
