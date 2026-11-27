<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Contacts\Integration\Activities;

use Foresi\Activities\Domain\ActivityTiming;
use Foresi\Activities\Domain\ActivityType;
use Foresi\Contacts\Application\CreateParty;
use Foresi\Contacts\Contract\ContactTabs;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PartyRole;
use Foresi\Tests\Contacts\Infrastructure\Support\ExampleContactTab;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * The Activities tab of a contact: its body is the timeline of the contact,
 * with "Log activity" and "Plan activity" opening their dialogs with the
 * contact linked; a meeting logged there shows on the timeline.
 */
final class ActivitiesContactTabTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
        $browser->seed('en');
        $browser->signIn();
        // The migration seeds the types of an instance; the test database is made from the mapping.
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        $em->persist(new ActivityType('meeting', null, ActivityTiming::Timed, 60, new \DateTimeImmutable()));
        $em->flush();
    }

    protected function tearDown(): void
    {
        ExampleContactTab::$enabled = false;
        parent::tearDown();
    }

    public function testActivitiesTabLoadsTheTimelineOfTheContact(): void
    {
        $id = $this->contact();
        $crawler = $this->client->request('GET', '/contacts/'.$id);
        $tabs = $crawler->filter('main nav[aria-label="Contact sections"] a')->each(static fn (Crawler $a): array => [trim($a->text()), (string) $a->attr('href')]);
        self::assertContains(['Activities', '/contacts/'.$id.'?tab=activities'], $tabs);
        self::assertLessThan(array_search('Documents', array_column($tabs, 0), true), array_search('Activities', array_column($tabs, 0), true), 'Activities come before the documents.');

        $crawler = $this->client->request('GET', '/contacts/'.$id.'?tab=activities');
        $src = (string) $crawler->filter('turbo-frame#'.ContactTabs::FRAME)->attr('src');
        self::assertStringStartsWith('/activities/for/contact/'.$id.'?return=', $src);

        $crawler = $this->client->request('GET', $src, server: ['HTTP_TURBO_FRAME' => ContactTabs::FRAME]);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('turbo-frame#'.ContactTabs::FRAME, 'No activities yet');
        $log = $crawler->filter('turbo-frame#'.ContactTabs::FRAME.' a[data-activity-log]');
        $plan = $crawler->filter('turbo-frame#'.ContactTabs::FRAME.' a[data-activity-plan]');
        self::assertCount(1, $log);
        self::assertCount(1, $plan);
        foreach ([$log, $plan] as $action) {
            $href = (string) $action->attr('href');
            self::assertStringContainsString('link_kind=contact', $href);
            self::assertStringContainsString('link_id='.$id, $href);
            self::assertStringContainsString('return=/contacts/'.$id.'?tab%3Dactivities', $href);
        }

        $crawler = $this->client->request('GET', (string) $log->attr('href'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-activity-link]', 'Example Trader');
        $form = $crawler->filter('#activity-form')->form();
        $form['subject'] = 'Kick-off meeting';
        $form['outcome'] = 'Agreed on an audit';
        $this->client->submit($form);
        self::assertResponseRedirects('/contacts/'.$id.'?tab=activities');

        $this->client->request('GET', $src, server: ['HTTP_TURBO_FRAME' => ContactTabs::FRAME]);
        self::assertSelectorTextContains('[data-activities-done] [data-activity-subject]', 'Kick-off meeting');
        self::assertSelectorTextContains('[data-activities-done] [data-activity-outcome]', 'Agreed on an audit');
    }

    public function testActivitiesTabFollowsTheTabsOfModulesBeforeIt(): void
    {
        // The deals (weight 100) and a module's tab of weight 101 come before the activities.
        ExampleContactTab::$enabled = true;
        $id = $this->contact();

        $crawler = $this->client->request('GET', '/contacts/'.$id);

        $tabs = array_column($crawler->filter('main nav[aria-label="Contact sections"] a')->each(static fn (Crawler $a): array => [trim($a->text())]), 0);
        self::assertSame(['Overview', 'Contact persons', 'Addresses', 'Bank accounts', 'History', 'Deals', 'Example'], \array_slice($tabs, 0, 7));
        self::assertSame('Activities', $tabs[7]);
    }

    private function contact(): string
    {
        // The fixture is set up by the application, not by the signed-in person.
        $tokens = self::getContainer()->get(TokenStorageInterface::class);
        $signedIn = $tokens->getToken();
        $tokens->setToken(null);
        $party = self::getContainer()->get(CreateParty::class)->create(PartyKind::SoleTrader, 'Example Trader', null, null, '', '', [PartyRole::Customer], new Address('Example Street 1', 'Example City', '100 00', 'CZ'));
        $tokens->setToken($signedIn);

        return $party->id();
    }
}
