<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Sales\Integration\Activities;

use Foresi\Activities\Domain\ActivityTiming;
use Foresi\Activities\Domain\ActivityType;
use Foresi\Tests\Sales\Support\SalesScreenTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * A deal's activities: "Log activity" and "Plan activity" on the deal's page
 * open the dialogs of Activities with the deal, its client and the deal's
 * contact persons linked, so what is logged on the deal shows on the client
 * and the person too, and the deal's timeline is a section of its page.
 */
final class SalesActivitiesIntegrationTest extends SalesScreenTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The migration seeds the types of an instance; the test database is made from the mapping.
        $this->em()->persist(new ActivityType('meeting', null, ActivityTiming::Timed, 60, new \DateTimeImmutable()));
        $this->em()->flush();
    }

    public function testActivityLoggedOnDealShowsOnClientToo(): void
    {
        $deal = $this->newDeal('Roof audit', [0]);
        $page = '/sales/deals/'.$deal->id();

        $crawler = $this->client->request('GET', $page);
        self::assertResponseIsSuccessful();
        $links = '&links%5B0%5D=contact:'.$this->company->id().'&links%5B1%5D=contact:'.$this->petra->id();
        foreach (['log' => 'Log activity', 'plan' => 'Plan activity'] as $mode => $label) {
            $action = $crawler->filter('[data-deal-action="'.$mode.'-activity"]');
            self::assertSame($label, trim($action->text()));
            self::assertNotNull($action->attr('data-dialog'));
            self::assertSame('/activities/new?mode='.$mode.'&link_kind=deal&link_id='.$deal->id().$links.'&return=/sales/deals/'.$deal->id(), $action->attr('href'));
        }
        // Log activity comes before Plan activity, both before what other modules add after them.
        self::assertSame(['log-activity', 'plan-activity'], \array_slice($crawler->filter('[data-deal-action]')->each(static fn (Crawler $a): string => (string) $a->attr('data-deal-action')), 0, 2));
        $timeline = (string) $crawler->filter('[data-deal-section="activities"] turbo-frame#deal-section-activities')->attr('src');
        self::assertSame('/activities/for/deal/'.$deal->id().'?return=/sales/deals/'.$deal->id(), $timeline);
        // The timeline is the first tab, shown by default; the documents are the next one, loaded only when chosen.
        self::assertSame([$page, $page.'?tab=documents'], $crawler->filter('[data-deal-tabs] nav a')->each(static fn (Crawler $tab): string => (string) $tab->attr('href')));
        self::assertSame(['Activities'], $crawler->filter('[data-deal-tabs] nav a[aria-current="page"]')->each(static fn (Crawler $tab): string => trim($tab->text())));
        self::assertSame(['activities'], $crawler->filter('[data-deal-section]')->each(static fn (Crawler $section): string => (string) $section->attr('data-deal-section')));
        // A tab that is not there shows the first one.
        $other = $this->client->request('GET', $page.'?tab=nothing');
        self::assertSame(['activities'], $other->filter('[data-deal-section]')->each(static fn (Crawler $section): string => (string) $section->attr('data-deal-section')));
        self::assertSame(['Activities'], $other->filter('[data-deal-tabs] nav a[aria-current="page"]')->each(static fn (Crawler $tab): string => trim($tab->text())));
        // Log activity is the one button beside Won; Plan activity waits in the "⋯" menu, and on a phone Log activity too.
        self::assertSame(['log-activity'], $crawler->filter('[data-more-actions-buttons] [data-deal-action]')->each(static fn (Crawler $a): string => (string) $a->attr('data-deal-action')));
        self::assertCount(1, $crawler->filter('[data-more-actions-menu] [data-deal-action="plan-activity"]'));
        self::assertStringContainsString('Log activity', $crawler->filter('[data-more-actions-phone]')->text());

        $crawler = $this->client->request('GET', (string) $crawler->filter('[data-deal-action="log-activity"]')->attr('href'));
        self::assertResponseIsSuccessful();
        $linked = $crawler->filter('[data-activity-link]')->each(static fn (Crawler $link): string => trim($link->text()));
        self::assertCount(3, $linked);
        foreach (['Roof audit', 'Alpha Example s.r.o.', 'Petra Example'] as $i => $name) {
            self::assertStringContainsString($name, $linked[$i]);
        }
        $form = $crawler->filter('#activity-form')->form();
        $form['subject'] = 'Kick-off meeting';
        $form['outcome'] = 'Agreed on an audit';
        $this->client->submit($form);
        self::assertResponseRedirects($page);

        foreach (['/activities/for/deal/'.$deal->id(), '/activities/for/contact/'.$this->company->id(), '/activities/for/contact/'.$this->petra->id()] as $record) {
            $this->client->request('GET', $record, server: ['HTTP_TURBO_FRAME' => 'deal-section-activities']);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('turbo-frame#deal-section-activities [data-activities-done] [data-activity-subject]', 'Kick-off meeting', $record);
        }
        // The person not on the deal has nothing.
        $this->client->request('GET', '/activities/for/contact/'.$this->karel->id(), server: ['HTTP_TURBO_FRAME' => 'deal-section-activities']);
        self::assertSelectorNotExists('[data-activities-done]');
    }
}
