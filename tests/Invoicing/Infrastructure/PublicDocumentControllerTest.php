<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Infrastructure;

use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Tests\Support\Mail\RecordingMailGateway;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class PublicDocumentControllerTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private KernelBrowser $client;
    private SignedInBrowser $browser;

    protected function setUp(): void
    {
        self::mockTime('2026-11-03 10:00:00');
        $this->client = self::createClient();
        $this->client->disableReboot();
        // A Czech browser, unless a test says otherwise.
        $this->client->setServerParameter('HTTP_ACCEPT_LANGUAGE', 'cs-CZ,cs;q=0.9');
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
        RecordingMailGateway::reset();
    }

    protected function tearDown(): void
    {
        RecordingMailGateway::reset();
        parent::tearDown();
    }

    public function testAMalformedTokenIs404(): void
    {
        $this->client->request('GET', '/d/'.str_repeat('A', 43));
        $unknown = (string) $this->client->getResponse()->getContent();

        foreach (['/d/short', '/d/'.str_repeat('A', 44), '/d/'.str_repeat('A', 42).'%24', '/d/'.str_repeat('A', 42).'.', '/d/short/pdf', '/d/'.str_repeat('A', 43).'/x/y', '/d/'.str_repeat('A', 300), '/d/'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseStatusCodeSame(404, $path);
            self::assertSame($unknown, (string) $this->client->getResponse()->getContent(), $path);
        }
    }

    public function testTheGonePageSpeaksTheBrowsersLanguage(): void
    {
        $this->client->request('GET', '/d/'.str_repeat('A', 43), server: ['HTTP_ACCEPT_LANGUAGE' => 'en-GB,en;q=0.9']);
        self::assertSelectorTextContains('h1', 'This link is not available');
        self::assertSame('en', $this->client->getCrawler()->filter('html')->attr('lang'));

        $this->client->request('GET', '/d/'.str_repeat('A', 43), server: ['HTTP_ACCEPT_LANGUAGE' => 'cs-CZ,cs;q=0.9,en;q=0.8']);
        self::assertSelectorTextContains('h1', 'Odkaz není k dispozici');

        $this->client->request('GET', '/d/'.str_repeat('A', 43), server: ['HTTP_ACCEPT_LANGUAGE' => 'fr-FR']);
        self::assertSelectorTextContains('h1', 'Odkaz není k dispozici');
    }
}
