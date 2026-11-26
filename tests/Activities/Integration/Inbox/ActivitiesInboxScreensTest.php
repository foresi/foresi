<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Activities\Integration\Inbox;

use Foresi\Activities\Domain\ActivityTiming;
use Foresi\Activities\Domain\ActivityType;
use Foresi\Inbox\Domain\InboxItem;
use Foresi\Inbox\Domain\ItemState;
use Foresi\Inbox\Infrastructure\Doctrine\DoctrineItemStore;
use Foresi\Tests\Inbox\Infrastructure\InboxScreenTestCase;
use Foresi\Tests\Inbox\Support\InboxMail;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * Log activity on the page of an inbox item: the dialog filled from the mail,
 * saving logs the activity and adds it to the item; while the E-mail type is
 * archived, the dialog says so and nothing is logged.
 */
final class ActivitiesInboxScreensTest extends InboxScreenTestCase
{
    private const string LOG_ACTIVITY = 'inbox.actions.log_activity';

    private string $callTypeId;

    protected function setUp(): void
    {
        parent::setUp();
        $call = new ActivityType('call', 'Call', ActivityTiming::Timed, 15, new \DateTimeImmutable('2026-11-25 10:00:00'));
        $this->em->persist($call);
        $this->em->flush();
        $this->callTypeId = $call->id();
    }

    public function testSavingLogsTheActivityAddsItAsAnOutputAndMarksTheItemDone(): void
    {
        $id = $this->receive(InboxMail::plain($this->address, subject: 'Call about the offer'));

        $crawler = $this->client->request('GET', '/inbox/'.$id.'/actions/'.self::LOG_ACTIVITY);
        self::assertResponseIsSuccessful();
        self::assertSame('Call about the offer', $crawler->filter('#inbox-action-subject')->attr('value'));
        $form = $crawler->filter('#inbox-action-form')->form();
        $form['typeId'] = $this->callTypeId;
        $done = $form['done'];
        self::assertInstanceOf(ChoiceFormField::class, $done);
        $done->tick();
        $this->client->submit($form);

        self::assertResponseRedirects('/inbox/'.$id);
        $activity = $this->em->getConnection()->fetchAssociative('SELECT id, subject FROM activities_activity WHERE type_id = ?', [$this->callTypeId]);
        self::assertIsArray($activity);
        self::assertSame('Call about the offer', $activity['subject']);
        $this->em->clear();
        $item = new DoctrineItemStore($this->em)->item($id);
        self::assertInstanceOf(InboxItem::class, $item);
        self::assertSame(ItemState::Done, $item->state());
        self::assertSame([[$activity['id']]], $this->em->getConnection()->fetchAllNumeric("SELECT record_id FROM inbox_item_output WHERE kind = 'activity'"));
    }

    public function testWhileTheEmailTypeIsArchivedTheDialogSaysSoAndNothingIsLogged(): void
    {
        $id = $this->receive(InboxMail::plain($this->address, subject: 'Call about the offer'));
        $this->em->getConnection()->executeStatement("UPDATE activities_type SET archived_at = '2026-11-25 10:00:00' WHERE type_key = 'email'");
        $this->em->clear();

        $crawler = $this->client->request('GET', '/inbox/'.$id.'/actions/'.self::LOG_ACTIVITY);
        $form = $crawler->filter('#inbox-action-form')->form();
        $form['typeId'] = $this->callTypeId;
        $done = $form['done'];
        self::assertInstanceOf(ChoiceFormField::class, $done);
        $done->tick();
        $crawler = $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('The activity type E-mail is archived', $crawler->filter('[data-inbox-action-error]')->text());
        self::assertEquals(0, $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM activities_activity'), 'Nothing was logged, so saving again makes no second one.');
        $this->em->clear();
        self::assertSame(ItemState::New, new DoctrineItemStore($this->em)->item($id)?->state());
    }
}
