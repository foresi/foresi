<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Activities\Integration\Contacts;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Activities\Application\ActivitiesStore;
use Foresi\Activities\Application\View\ActivityTypeLists;
use Foresi\Activities\Domain\Activity;
use Foresi\Activities\Domain\ActivitySchedule;
use Foresi\Activities\Domain\ActivityTiming;
use Foresi\Activities\Domain\ActivityType;
use Foresi\Contacts\Application\CreateParty;
use Foresi\Identity\Domain\Role;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PartyRole;
use Foresi\Shared\Domain\Id;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Tests\Ui\Support\ListPageAssertions;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * The screens of Activities on a contact: plan and log in a dialog, "Done and
 * plan next", change, delete with Undo, the activity as a record, and the
 * types under Settings. Guests see none of it.
 */
final class ActivityScreensTest extends WebTestCase
{
    use ListPageAssertions;

    private KernelBrowser $client;
    private SignedInBrowser $browser;
    private string $contactId;
    private string $return;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->browser = new SignedInBrowser($this->client, $this->em());
        if ('testAGuestSeesNoActivities' === $this->name()) {
            return;
        }
        $this->browser->seed('en');
        $this->browser->signIn();
        foreach ([['meeting', ActivityTiming::Timed, 60], ['task', ActivityTiming::Date, null], ['note', ActivityTiming::None, null]] as [$key, $timing, $duration]) {
            $this->em()->persist(new ActivityType($key, null, $timing, $duration, new \DateTimeImmutable('2026-01-01')));
        }
        $this->em()->flush();
        $this->contactId = $this->contact();
        $this->return = '/contacts/'.$this->contactId.'?tab=activities';
    }

    public function testPlanLogCompleteAndPlanNextOnTheTimeline(): void
    {
        $crawler = $this->client->request('GET', '/activities/new?mode=plan&link_kind=contact&link_id='.$this->contactId.'&return='.rawurlencode($this->return));
        self::assertResponseIsSuccessful();
        self::assertSame($this->type('meeting')->id(), $crawler->filter('#typeId option[selected]')->attr('value'), 'A plan opens on a timed type.');
        self::assertCount(0, $crawler->filter('#doneAt'));
        $form = $crawler->filter('#activity-form')->form();
        $form['subject'] = 'Kick-off meeting';
        $form['plannedAt'] = '2026-11-25T10:00';
        $form['plannedOn'] = '';
        $this->client->submit($form);
        self::assertResponseRedirects($this->return);

        $crawler = $this->client->request('GET', '/activities/new?mode=log&link_kind=contact&link_id='.$this->contactId.'&return='.rawurlencode($this->return));
        $form = $crawler->filter('#activity-form')->form();
        $form['subject'] = 'First call';
        $form['doneAt'] = '2026-11-20T09:00';
        $form['outcome'] = 'Wants a roof audit';
        $form['file'] = $this->recording();
        $this->client->submit($form);
        self::assertResponseRedirects($this->return);

        $crawler = $this->timeline();
        self::assertSame(['Kick-off meeting'], $crawler->filter('[data-activities-planned] [data-activity-subject]')->each(static fn ($node): string => $node->text()));
        self::assertSame(['First call'], $crawler->filter('[data-activities-done] [data-activity-subject]')->each(static fn ($node): string => $node->text()));
        self::assertSelectorTextContains('[data-activities-done] [data-activity-files]', 'recording.m4a');
        self::assertSelectorTextContains('[data-activities-planned] [data-activity-type]', 'Meeting');
        self::assertCount(0, $crawler->filter('[data-activities-planned] [data-activity-links]'), 'The contact itself is not listed on its own timeline.');

        $complete = $crawler->filter('[data-activities-planned] a[data-activity-complete]')->attr('href');
        $crawler = $this->client->request('GET', (string) $complete);
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('#activity-complete-form')->form();
        $form['outcome'] = 'Agreed on an audit';
        $form['nextTypeId'] = $this->type('task')->id();
        $form['nextSubject'] = 'Send the audit offer';
        $form['nextPlannedOn'] = '2026-11-27';
        $form['nextPlannedAt'] = '';
        $this->client->submit($form);
        self::assertResponseRedirects($this->return);

        $crawler = $this->timeline();
        self::assertSame(['Send the audit offer'], $crawler->filter('[data-activities-planned] [data-activity-subject]')->each(static fn ($node): string => $node->text()));
        self::assertSame(['Kick-off meeting', 'First call'], $crawler->filter('[data-activities-done] [data-activity-subject]')->each(static fn ($node): string => $node->text()));
        self::assertSelectorTextContains('[data-activities-done] [data-activity-outcome]', 'Agreed on an audit');
    }

    public function testTheNewActivityDialogTakesSeveralLinksFromTheAddress(): void
    {
        $person = $this->contact('Example Person');
        // A malformed entry and a repeat of the first link are left out.
        $url = '/activities/new?mode=log&link_kind=contact&link_id='.$this->contactId.'&links[]=contact:'.$person.'&links[]=contact:not-an-id&links[]=contact:'.$this->contactId;
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSame(['Example Trader', 'Example Person'], $crawler->filter('#activity-form [data-activity-link] [data-activity-link-label]')->each(static fn ($node): string => $node->text()));
        self::assertSame(['contact:'.$person], $crawler->filter('#activity-form input[name="links[]"]')->each(static fn ($node): string => (string) $node->attr('value')));
        $form = $crawler->filter('#activity-form')->form();
        $form['subject'] = 'Call with both';
        $form['doneAt'] = '2026-11-20T09:00';
        $this->client->submit($form);
        self::assertResponseRedirects();
        $id = substr((string) $this->client->getResponse()->headers->get('Location'), \strlen('/activities/'));

        $links = self::getContainer()->get(ActivitiesStore::class)->links($this->activity($id));
        $pairs = array_map(static fn ($link): string => $link->kind().':'.$link->targetId(), $links);
        sort($pairs);
        $expected = ['contact:'.$this->contactId, 'contact:'.$person];
        sort($expected);
        self::assertSame($expected, $pairs);
    }

    public function testARefusedNextStepKeepsTheDialogOpenAndTheActivityPlanned(): void
    {
        $activity = $this->planned();
        $crawler = $this->client->request('GET', '/activities/'.$activity.'/complete');
        $form = $crawler->filter('#activity-complete-form')->form();
        $form['nextTypeId'] = $this->type('task')->id();
        $form['nextSubject'] = '';
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#nextSubject[aria-invalid="true"]');
        $this->em()->clear();
        self::assertFalse($this->activity($activity)->isDone());

        $form = $this->client->getCrawler()->filter('#activity-complete-form')->form();
        $planNext = $form->get('planNext');
        self::assertInstanceOf(ChoiceFormField::class, $planNext);
        $planNext->untick();
        $this->client->submit($form);
        self::assertResponseRedirects('/activities/'.$activity);
        $this->em()->clear();
        self::assertTrue($this->activity($activity)->isDone(), 'Without the next step it is only marked done.');
    }

    public function testTheActivityAsARecordEditDeleteAndUndo(): void
    {
        $activity = $this->planned();
        $crawler = $this->client->request('GET', '/activities/'.$activity);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Kick-off meeting');
        self::assertSelectorTextContains('[data-activity-links]', 'Example Trader');
        self::assertSelectorExists('a[data-activity-complete]');

        $crawler = $this->client->request('GET', '/activities/'.$activity.'/edit');
        $form = $crawler->filter('#activity-form')->form();
        $form['subject'] = 'Kick-off on site';
        $form['body'] = 'Bring the **drone**.';
        $this->client->submit($form);
        self::assertResponseRedirects('/activities/'.$activity);
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Kick-off on site');
        self::assertSelectorTextContains('[data-activity-body] strong', 'drone');

        $form = $this->client->getCrawler()->filter('form[data-activity-trash]')->form();
        $this->client->submit($form);
        self::assertResponseRedirects('/contacts/'.$this->contactId);
        $this->em()->clear();
        self::assertTrue($this->activity($activity)->isTrashed());
        $crawler = $this->timeline();
        self::assertCount(0, $crawler->filter('[data-activity="'.$activity.'"]'));

        $this->client->request('POST', '/activities/'.$activity.'/restore', ['_token' => $this->token()]);
        self::assertResponseRedirects('/activities/'.$activity);
        $this->em()->clear();
        self::assertFalse($this->activity($activity)->isTrashed());
    }

    public function testTheTypesAreAListWithViews(): void
    {
        self::assertListWithViews(self::getContainer()->get(ActivityTypeLists::class), 'activities.types', ['name', 'timing', 'archived'], ['all' => false]);
        $types = fn (Crawler $crawler): array => $crawler->filter('[data-activity-types] tbody tr[data-id]')->each(static fn (Crawler $row): string => trim($row->filter('th, td')->first()->text()));

        $crawler = $this->client->request('GET', '/settings/activities?cols=name,key');
        self::assertSame(['name', 'key'], $crawler->filter('[data-activity-types] colgroup col[data-column]')->each(static fn (Crawler $col): string => (string) $col->attr('data-column')));
        self::assertListTable($crawler, '[data-activity-types]', ['widths' => '']);

        // A type is found by its translated name, and the timing is a field of the filter.
        self::assertSame(['Meeting'], $types($this->client->request('GET', '/settings/activities?q=meet')));
        self::assertSame(['Note'], $types(self::applyBuilder($this->client, '/settings/activities', 'name', 'No')));
        $this->client->request('GET', '/settings/activities?'.http_build_query(['conditions' => '1', 'c' => [['field' => 'timing', 'op' => 'is', 'values' => ['date']]]]));
        self::assertSame(['Task'], $types($this->client->followRedirect()));
    }

    public function testTheTypesUnderSettings(): void
    {
        $crawler = $this->client->request('GET', '/settings/activities');
        self::assertResponseIsSuccessful();
        $text = $crawler->filter('[data-activity-types]')->text();
        foreach (['Meeting', 'Task', 'Note', 'On a day'] as $shown) {
            self::assertStringContainsString($shown, $text, 'Seeded types show under their translated names.');
        }

        $crawler = $this->client->request('GET', '/settings/activities/types/new');
        $form = $crawler->filter('#activity-type-form')->form();
        $form['name'] = 'Site visit';
        $form['key'] = 'site_visit';
        $form['timing'] = 'timed';
        $form['defaultDurationMinutes'] = '90';
        $this->client->submit($form);
        self::assertResponseRedirects('/settings/activities');
        $visit = $this->type('site_visit');
        self::assertSame(['Site visit', 90], [$visit->name(), $visit->defaultDurationMinutes()]);

        $meeting = $this->type('meeting');
        $crawler = $this->client->request('GET', '/settings/activities/types/'.$meeting->id().'/edit');
        $form = $crawler->filter('#activity-type-form')->form();
        $form['defaultDurationMinutes'] = '45';
        $this->client->submit($form);
        $this->em()->clear();
        $meeting = $this->type('meeting');
        self::assertSame([null, 45], [$meeting->name(), $meeting->defaultDurationMinutes()], 'A seeded type keeps its translated name when it is not renamed.');

        $this->client->request('POST', '/settings/activities/types/'.$visit->id().'/archive', ['_token' => $this->token()]);
        self::assertResponseRedirects('/settings/activities');
        $this->em()->clear();
        self::assertTrue($this->type('site_visit')->isArchived());
    }

    public function testAGuestSeesNoActivities(): void
    {
        $this->browser->seed(role: Role::Guest);
        $this->browser->signIn();
        $type = new ActivityType('meeting', null, ActivityTiming::Timed, 60, new \DateTimeImmutable('2026-01-01'));
        $activity = new Activity($type, 'Kick-off meeting', '', new ActivitySchedule(plannedAt: new \DateTimeImmutable('2026-11-25 10:00')), null, null, Id::generate(), Id::generate(), new \DateTimeImmutable('2026-11-22'));
        $this->em()->persist($type);
        $this->em()->persist($activity);
        $this->em()->flush();

        foreach (['/activities/'.$activity->id(), '/activities/for/contact/'.Id::generate(), '/settings/activities', '/activities/new?mode=plan'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseStatusCodeSame(403, $path);
        }
    }

    private function planned(): string
    {
        $crawler = $this->client->request('GET', '/activities/new?mode=plan&link_kind=contact&link_id='.$this->contactId);
        $form = $crawler->filter('#activity-form')->form();
        $form['subject'] = 'Kick-off meeting';
        $form['plannedAt'] = '2026-11-25T10:00';
        $form['plannedOn'] = '';
        $this->client->submit($form);
        self::assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');

        return substr($location, \strlen('/activities/'));
    }

    private function timeline(): Crawler
    {
        $crawler = $this->client->request('GET', '/activities/for/contact/'.$this->contactId.'?return='.rawurlencode($this->return));
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /** A file named as a recording, in a directory of its own. */
    private function recording(): string
    {
        $directory = sys_get_temp_dir().'/activities-'.Id::generate();
        mkdir($directory);
        file_put_contents($directory.'/recording.m4a', 'not really audio');

        return $directory.'/recording.m4a';
    }

    private function token(): string
    {
        $crawler = $this->client->request('GET', '/settings/activities/types/new');

        return (string) $crawler->filter('#activity-type-form input[name="_token"]')->attr('value');
    }

    private function type(string $key): ActivityType
    {
        $type = self::getContainer()->get(ActivitiesStore::class)->typeByKey($key);
        self::assertInstanceOf(ActivityType::class, $type);

        return $type;
    }

    private function activity(string $id): Activity
    {
        $activity = self::getContainer()->get(ActivitiesStore::class)->activity($id);
        self::assertInstanceOf(Activity::class, $activity);

        return $activity;
    }

    private function contact(string $name = 'Example Trader'): string
    {
        // The fixture is set up by the application, not by the signed-in person.
        $tokens = self::getContainer()->get(TokenStorageInterface::class);
        $signedIn = $tokens->getToken();
        $tokens->setToken(null);
        $party = self::getContainer()->get(CreateParty::class)->create(PartyKind::SoleTrader, $name, null, null, '', '', [PartyRole::Customer], new Address('Example Street 1', 'Example City', '100 00', 'CZ'));
        $tokens->setToken($signedIn);

        return $party->id();
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }
}
