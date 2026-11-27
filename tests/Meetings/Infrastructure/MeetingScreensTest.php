<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Meetings\Infrastructure;

use Foresi\Identity\Domain\Role;
use Foresi\Meetings\Domain\Meeting;
use Foresi\Meetings\Domain\MeetingSeries;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The screens of Meetings: the lists start empty, a series is made in its
 * dialog, and a new meeting of it opens with the series' agenda in the minutes.
 */
final class MeetingScreensTest extends WebTestCase
{
    private const string AGENDA = "## Numbers\n\n## Next steps";

    private KernelBrowser $client;
    private SignedInBrowser $browser;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
    }

    public function testASeriesAndAMeetingFromTheirDialogs(): void
    {
        $this->signIn();
        $this->client->request('GET', '/meetings');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Meetings');
        self::assertSelectorTextContains('body', 'No meetings yet');
        $this->client->request('GET', '/meetings/series');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'No series yet');

        $crawler = $this->client->request('GET', '/meetings/series/new');
        self::assertResponseIsSuccessful();
        $values = $crawler->filter('#series-form')->form()->getPhpValues();
        $this->client->request('POST', '/meetings/series/new', ['name' => 'Production weekly', 'agendaTemplate' => self::AGENDA] + $values);
        self::assertResponseRedirects('/meetings/series');
        $series = $this->one(MeetingSeries::class);
        self::assertSame(['Production weekly', self::AGENDA], [$series->name(), $series->agendaTemplate()]);

        $crawler = $this->client->request('GET', '/meetings/new', ['series' => $series->id()]);
        self::assertResponseIsSuccessful();
        $values = $crawler->filter('#meeting-form')->form()->getPhpValues();
        self::assertSame($series->id(), $values['seriesId'] ?? null);
        $this->client->request('POST', '/meetings/new', ['heldOn' => '2026-11-30'] + $values);
        $meeting = $this->one(Meeting::class);
        self::assertResponseRedirects('/meetings/'.$meeting->id());

        $crawler = $this->client->request('GET', '/meetings/'.$meeting->id());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Production weekly – 2026-11-30');
        self::assertSame(self::AGENDA, $crawler->filter('textarea[name="minutes"]')->text(normalizeWhitespace: false));
        $this->client->request('GET', '/meetings');
        self::assertSelectorTextContains('table', 'Production weekly – 2026-11-30');
    }

    public function testAGuestIsRefused(): void
    {
        $this->signIn(Role::Guest);

        foreach (['/meetings', '/meetings/series', '/meetings/new', '/meetings/series/new'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
    }

    private function signIn(Role $role = Role::Owner): void
    {
        $this->browser->seed(role: $role);
        // A firm, so the instance is past its first-run setup.
        $em = $this->browser->em();
        $em->persist(new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, ''));
        $em->flush();
        $this->browser->signIn();
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function one(string $class): object
    {
        $em = $this->browser->em();
        $em->clear();
        $found = $em->getRepository($class)->findOneBy([]);
        self::assertInstanceOf($class, $found);

        return $found;
    }
}
