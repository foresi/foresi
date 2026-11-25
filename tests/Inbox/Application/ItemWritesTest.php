<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Application;

use Foresi\Activities\Application\ActivityTargets;
use Foresi\Activities\Contract\LinkableRecords;
use Foresi\Identity\Domain\Role;
use Foresi\Inbox\Application\Api\CreateContactFromItemInput;
use Foresi\Inbox\Application\Api\DiscardItemInput;
use Foresi\Inbox\Application\Api\LinkItemInput;
use Foresi\Inbox\Application\Api\MarkItemDoneInput;
use Foresi\Inbox\Application\Api\MatchItemAgainInput;
use Foresi\Inbox\Application\Api\MatchItemInput;
use Foresi\Inbox\Application\Api\ReopenItemInput;
use Foresi\Inbox\Application\Api\RestoreItemInput;
use Foresi\Inbox\Application\Api\TrashItemInput;
use Foresi\Inbox\Application\EmailActivity;
use Foresi\Inbox\Application\InboxAccess;
use Foresi\Inbox\Application\InboxTransaction;
use Foresi\Inbox\Application\ItemReads;
use Foresi\Inbox\Application\ItemStore;
use Foresi\Inbox\Application\ItemWrites;
use Foresi\Inbox\Application\PurgeTrashedItems;
use Foresi\Inbox\Application\SenderMatching;
use Foresi\Inbox\Contract\InboxItems;
use Foresi\Inbox\Contract\InboxItemView;
use Foresi\Inbox\Contract\InboxOutput;
use Foresi\Inbox\Domain\InboxItem;
use Foresi\Inbox\Domain\ItemOutput;
use Foresi\Inbox\Domain\ItemState;
use Foresi\Inbox\Domain\MatchBasis;
use Foresi\Inbox\Infrastructure\Doctrine\DoctrineItemStore;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Mail\Contract\MessageRetention;
use Foresi\Mail\Contract\Messages;
use Foresi\Party\Contract\ContactCreation;
use Foresi\Party\Contract\ContactPersonWrites;
use Foresi\Party\Contract\PartyDirectory;
use Foresi\Shared\Application\Event\EventRecorder;
use Foresi\Shared\Application\Markdown;
use Foresi\Shared\Contract\Refused;
use Foresi\Shared\Domain\Id;
use Foresi\Tests\Activities\Support\FakeActivityTarget;
use Foresi\Tests\Inbox\Support\InboxMail;
use Foresi\Tests\Inbox\Support\InboxTestCase;

/**
 * What the owner does with an item: match, state, link and trash, the one
 * e-mail activity it gives, the mail Mail keeps or removes, and who sees it.
 */
final class ItemWritesTest extends InboxTestCase
{
    public function testMatchSetsAContactByHandWithItsOneCompanyAndNoneClearsIt(): void
    {
        $itemId = $this->receive(InboxMail::plain($this->address, from: 'Someone <someone@unknown.example.com>'));
        $person = $this->contact('person', 'Client A', self::CLIENT);

        $item = $this->writes()->match(new MatchItemInput($itemId, $person));
        self::assertSame([$person, null, MatchBasis::Manual], [$item->contactId(), $item->companyId(), $item->matchBasis()]);

        $this->writes()->match(new MatchItemInput($itemId, null));
        $item = $this->item($itemId);
        self::assertSame([null, null, null], [$item->contactId(), $item->companyId(), $item->matchBasis()]);
    }

    public function testAPersonOfOneCompanyGivesTheCompanyToTheItemAndItsActivity(): void
    {
        $itemId = $this->receive(InboxMail::plain($this->address, from: 'Someone <someone@unknown.example.com>'));
        $person = $this->contact('person', 'Client A', self::CLIENT);
        $company = $this->contact('company', 'Client A s.r.o.', 'office@client-a.example.com', '12345679');
        self::getContainer()->get(ContactPersonWrites::class)->link($company, $person, 'Director');
        $output = $this->contact('person', 'Client C', 'client.c@client-c.example.com');

        $this->writes()->match(new MatchItemInput($itemId, $person));
        $this->writes()->link(new LinkItemInput($itemId, 'contact', $output));

        $item = $this->item($itemId);
        self::assertSame([$person, $company], [$item->contactId(), $item->companyId()]);
        self::assertEqualsCanonicalizing(['contact:'.$person, 'contact:'.$company, 'contact:'.$output], $this->links((string) $item->emailActivityId()));
    }

    public function testMatchRefusesAnUnknownOrArchivedContact(): void
    {
        $itemId = $this->receive(InboxMail::plain($this->address));
        $archived = $this->contact('person', 'Client Gone', 'gone@client-a.example.com');
        $this->archive($archived);

        $this->assertRefused('contact_unknown', fn (): InboxItem => $this->writes()->match(new MatchItemInput($itemId, Id::generate())));
        $this->assertRefused('contact_unknown', fn (): InboxItem => $this->writes()->match(new MatchItemInput($itemId, 'not-an-id')));
        $this->assertRefused('contact_archived', fn (): InboxItem => $this->writes()->match(new MatchItemInput($itemId, $archived)));
        self::assertNull($this->item($itemId)->contactId());
    }

    public function testMatchAgainFindsTheSenderAddedSinceTheMailArrived(): void
    {
        $itemId = $this->receive(InboxMail::plain($this->address));
        self::assertNull($this->item($itemId)->contactId());
        $person = $this->contact('person', 'Client A', self::CLIENT);

        $this->writes()->matchAgain(new MatchItemAgainInput($itemId));

        $item = $this->item($itemId);
        self::assertSame([$person, MatchBasis::Address], [$item->contactId(), $item->matchBasis()]);
    }

    public function testCreateContactMakesTheSenderAContactAndMatchesTheItem(): void
    {
        $itemId = $this->receive(InboxMail::plain($this->address));

        $item = $this->writes()->createContact(new CreateContactFromItemInput($itemId, 'person', 'Client A', 'Example Street 2', 'Example City', '110 00', 'CZ'));

        $contactId = $item->contactId();
        self::assertNotNull($contactId);
        self::assertSame(MatchBasis::Manual, $item->matchBasis());
        self::assertSame(self::CLIENT, $this->em->getConnection()->fetchOne('SELECT address FROM party_email WHERE party_id = ?', [$contactId]));
    }

    public function testCreateContactPassesTheAddressBooksRefusalOn(): void
    {
        $existing = $this->contact('company', 'Client B s.r.o.', 'office@client-b.example.com', '12345679');
        $itemId = $this->receive(InboxMail::plain($this->address));

        try {
            $this->writes()->createContact(new CreateContactFromItemInput($itemId, 'company', 'Client B', 'Example Street 2', 'Example City', '110 00', 'CZ', '12345679'));
            self::fail('A duplicate is refused.');
        } catch (Refused $refused) {
            self::assertSame('duplicate', $refused->reason());
            self::assertSame(['existing_ids' => [$existing]], $refused->details());
        }
        self::assertNull($this->item($itemId)->contactId());
    }

    public function testTheStatesRefuseWhatTheyAreAlready(): void
    {
        $itemId = $this->receive(InboxMail::plain($this->address));

        $this->assertRefused('already_new', fn (): InboxItem => $this->writes()->reopen(new ReopenItemInput($itemId)));
        $item = $this->writes()->done(new MarkItemDoneInput($itemId));
        self::assertSame([ItemState::Done, $this->owner->id()], [$item->state(), $item->stateChangedBy()]);
        $this->assertRefused('already_done', fn (): InboxItem => $this->writes()->done(new MarkItemDoneInput($itemId)));
        $this->writes()->discard(new DiscardItemInput($itemId));
        $this->assertRefused('already_discarded', fn (): InboxItem => $this->writes()->discard(new DiscardItemInput($itemId)));
        self::assertSame(ItemState::New, $this->writes()->reopen(new ReopenItemInput($itemId))->state());
    }

    public function testDiscardReleasesTheRawFileAfter30DaysAndReopenCancelsIt(): void
    {
        $itemId = $this->receive(InboxMail::plain($this->address));
        $messageId = $this->item($itemId)->mailMessageId();
        self::assertNull($this->purgeAfter($messageId), 'A received mail is kept.');

        $this->writes()->discard(new DiscardItemInput($itemId));
        self::assertSame('2026-12-25 10:00:00', $this->purgeAfter($messageId));

        $this->writes()->reopen(new ReopenItemInput($itemId));
        self::assertNull($this->purgeAfter($messageId));
    }

    public function testADiscardedItemWithAnOutputKeepsItsMail(): void
    {
        $itemId = $this->receive(InboxMail::plain($this->address));
        $messageId = $this->item($itemId)->mailMessageId();
        $this->writes()->link(new LinkItemInput($itemId, 'contact', $this->contact('person', 'Client A', self::CLIENT)));

        $this->writes()->discard(new DiscardItemInput($itemId));

        self::assertNull($this->purgeAfter($messageId));
    }

    public function testLinkingADiscardedItemKeepsItsMailAgain(): void
    {
        $itemId = $this->receive(InboxMail::plain($this->address));
        $messageId = $this->item($itemId)->mailMessageId();
        $this->writes()->discard(new DiscardItemInput($itemId));

        $this->writes()->link(new LinkItemInput($itemId, 'contact', $this->contact('person', 'Client A', self::CLIENT)));

        self::assertNull($this->purgeAfter($messageId));
    }

    public function testOneEmailGivesOneActivityHoweverManyOutputs(): void
    {
        $person = $this->contact('person', 'Client A', self::CLIENT);
        $other = $this->contact('person', 'Client C', 'client.c@client-c.example.com');
        $third = $this->contact('person', 'Client D', 'client.d@client-d.example.com');
        $itemId = $this->receive(InboxMail::plain($this->address, body: "Hello, please find our offer below.\r\nKind regards\r\n"));
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM activities_activity'), 'Never on receive.');

        $this->writes()->link(new LinkItemInput($itemId, 'contact', $other));
        $this->writes()->link(new LinkItemInput($itemId, 'contact', $third));

        $item = $this->item($itemId);
        $activityId = $item->emailActivityId();
        self::assertNotNull($activityId);
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM activities_activity'));
        $activity = $this->em->getConnection()->fetchAssociative('SELECT a.subject, a.body, a.done_at, a.owner_user_id, t.type_key FROM activities_activity a JOIN activities_type t ON t.id = a.type_id WHERE a.id = ?', [$activityId]);
        self::assertIsArray($activity);
        self::assertSame('Offer for the new office', $activity['subject']);
        self::assertSame("From: client\\.a\\@client\\-a\\.example\\.com\nDate: 2026-11-25 10:00\n\nHello\\, please find our offer below\\.\nKind regards\n", $activity['body']);
        self::assertSame('email', $activity['type_key']);
        self::assertSame($this->owner->id(), $activity['owner_user_id']);
        self::assertIsString($activity['done_at']);
        self::assertStringStartsWith('2026-11-25 10:00:00', $activity['done_at'], 'Done when the mail arrived.');
        self::assertEqualsCanonicalizing(['contact:'.$person, 'contact:'.$other, 'contact:'.$third], $this->links($activityId), 'The matched contact and every output.');
        self::assertSame(2, $this->rows('SELECT COUNT(*) FROM inbox_item_output'));
    }

    public function testLinkRefusesAnUnknownKindARecordItCannotLinkAndOneLinkedAlready(): void
    {
        $person = $this->contact('person', 'Client A', self::CLIENT);
        $archived = $this->contact('person', 'Client Gone', 'gone@client-a.example.com');
        $this->archive($archived);
        $itemId = $this->receive(InboxMail::plain($this->address));

        $this->assertRefused('kind_unknown', fn (): InboxItem => $this->writes()->link(new LinkItemInput($itemId, 'spaceship', Id::generate())));
        $this->assertRefused('record_not_linkable', fn (): InboxItem => $this->writes()->link(new LinkItemInput($itemId, 'contact', $archived)));
        $this->assertRefused('record_not_linkable', fn (): InboxItem => $this->writes()->link(new LinkItemInput($itemId, 'contact', Id::generate())));
        $this->writes()->link(new LinkItemInput($itemId, 'contact', $person));
        $this->assertRefused('already_linked', fn (): InboxItem => $this->writes()->link(new LinkItemInput($itemId, 'contact', $person)));
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM inbox_item_output'));
    }

    public function testTheEventsCarryTheItemIdAndTheKindOnly(): void
    {
        $person = $this->contact('person', 'Client A', self::CLIENT);
        $itemId = $this->receive(InboxMail::plain($this->address));

        $this->writes()->link(new LinkItemInput($itemId, 'contact', $person));
        $this->writes()->done(new MarkItemDoneInput($itemId));
        $this->writes()->discard(new DiscardItemInput($itemId));
        $this->writes()->reopen(new ReopenItemInput($itemId));

        self::assertSame([['item_id' => $itemId, 'kind' => 'contact']], $this->eventData('inbox.item.linked'));
        self::assertSame([['item_id' => $itemId]], $this->eventData('inbox.item.done'));
        self::assertSame([['item_id' => $itemId]], $this->eventData('inbox.item.discarded'));
        self::assertSame([['item_id' => $itemId]], $this->eventData('inbox.item.reopened'));
    }

    public function testAnItemIsTheOwnersOnly(): void
    {
        $itemId = $this->receive(InboxMail::plain($this->address));
        $this->signIn($this->person('member@example.com', Role::Member));

        $this->assertRefused('not_found', fn (): InboxItem => $this->writes()->done(new MarkItemDoneInput($itemId)));
        $this->assertRefused('not_found', fn (): InboxItem => $this->writes()->trash(new TrashItemInput($itemId)));
        $this->assertRefused('not_found', fn () => self::getContainer()->get(ItemReads::class)->get($itemId));
        self::assertNull(self::getContainer()->get(InboxItems::class)->find($itemId));
        self::assertSame(ItemState::New, $this->item($itemId)->state());
    }

    public function testALinkedEmailIsVisibleThroughTheActivityOnly(): void
    {
        $person = $this->contact('person', 'Client A', self::CLIENT);
        $itemId = $this->receive(InboxMail::plain($this->address));
        $this->writes()->link(new LinkItemInput($itemId, 'contact', $person));
        $activityId = (string) $this->item($itemId)->emailActivityId();

        // A colleague who sees the contact sees the e-mail on its timeline, and through the contract.
        $this->signIn($this->person('member@example.com', Role::Member));
        self::assertTrue(self::getContainer()->get(LinkableRecords::class)->seesActivity($activityId));
        $view = self::getContainer()->get(InboxItems::class)->find($itemId);
        self::assertInstanceOf(InboxItemView::class, $view);
        self::assertSame("Hello, please find our offer below.\r\n", $view->text);
        self::assertSame([], $view->threadItemIds, 'The owner\'s other mails stay the owner\'s.');
        // The inbox itself stays the owner's.
        $this->assertRefused('not_found', fn () => self::getContainer()->get(ItemReads::class)->get($itemId));
        $this->assertRefused('not_found', fn (): InboxItem => $this->writes()->done(new MarkItemDoneInput($itemId)));
    }

    public function testAnEmailLinkedOnlyToRecordsAColleagueCannotSeeStaysTheOwners(): void
    {
        $deals = new FakeActivityTarget('deal');
        $seen = Id::generate();
        $unseen = Id::generate();
        $deals->add($seen, 'Example deal');
        $deals->add($unseen, 'Secret deal');
        self::getContainer()->set(ActivityTargets::class, new ActivityTargets([$deals]));
        $itemId = $this->receive(InboxMail::plain($this->address, from: 'Someone <someone@unknown.example.com>'));
        $this->writes()->link(new LinkItemInput($itemId, 'deal', $unseen));

        $deals->hide($unseen);
        $this->signIn($this->person('member@example.com', Role::Member));
        self::assertNull(self::getContainer()->get(InboxItems::class)->find($itemId), 'No record of it is the colleague\'s to see.');

        $deals->remove($unseen);
        $deals->add($unseen, 'Secret deal');
        $this->signIn($this->owner);
        $this->writes()->link(new LinkItemInput($itemId, 'deal', $seen));
        $deals->hide($unseen);
        $this->signIn($this->person('other@example.com', Role::Member));
        $view = self::getContainer()->get(InboxItems::class)->find($itemId);
        self::assertInstanceOf(InboxItemView::class, $view);
        self::assertSame([$seen], array_map(static fn (InboxOutput $output): string => $output->recordId, $view->outputs), 'Only the records the colleague sees.');
    }

    public function testALinkMadeAtTheSameMomentElsewhereIsAlreadyLinked(): void
    {
        $person = $this->contact('person', 'Client A', self::CLIENT);
        $itemId = $this->receive(InboxMail::plain($this->address));
        $this->writes()->link(new LinkItemInput($itemId, 'contact', $person));
        // The other request added the output after this one looked for it.
        $store = new DoctrineItemStore($this->em);
        $blind = new readonly class($store) implements ItemStore {
            public function __construct(private ItemStore $store)
            {
            }

            public function item(string $id): ?InboxItem
            {
                return $this->store->item($id);
            }

            public function itemOfMessage(string $mailMessageId): ?InboxItem
            {
                return $this->store->itemOfMessage($mailMessageId);
            }

            public function page(string $ownerUserId, ?ItemState $state, bool $unmatched, ?string $afterId, int $limit): array
            {
                return $this->store->page($ownerUserId, $state, $unmatched, $afterId, $limit);
            }

            public function countInState(string $ownerUserId, ItemState $state, int $limit): int
            {
                return $this->store->countInState($ownerUserId, $state, $limit);
            }

            public function threadSizes(string $ownerUserId, array $threadKeys): array
            {
                return $this->store->threadSizes($ownerUserId, $threadKeys);
            }

            public function threadOf(InboxItem $item): array
            {
                return $this->store->threadOf($item);
            }

            public function outputs(InboxItem $item): array
            {
                return [];
            }

            public function outputCounts(array $itemIds): array
            {
                return $this->store->outputCounts($itemIds);
            }

            public function trashedBefore(\DateTimeImmutable $time): array
            {
                return $this->store->trashedBefore($time);
            }

            public function add(InboxItem|ItemOutput $record): void
            {
                $this->store->add($record);
            }

            public function remove(InboxItem|ItemOutput $record): void
            {
                $this->store->remove($record);
            }
        };
        $this->em->clear();
        $c = self::getContainer();
        $writes = new ItemWrites($c->get(InboxAccess::class), $blind, $c->get(InboxTransaction::class), $c->get(EventRecorder::class), $c->get(InstanceClock::class), $c->get(PartyDirectory::class), $c->get(ContactCreation::class), $c->get(SenderMatching::class), $c->get(Messages::class), $c->get(MessageRetention::class), $c->get(LinkableRecords::class), $c->get(EmailActivity::class));

        $this->assertRefused('already_linked', fn (): InboxItem => $writes->link(new LinkItemInput($itemId, 'contact', $person)));
    }

    public function testATrashedItemIsReachedOnlyByRestore(): void
    {
        $itemId = $this->receive(InboxMail::plain($this->address));

        $item = $this->writes()->trash(new TrashItemInput($itemId));
        self::assertTrue($item->isTrashed());
        $this->writes()->trash(new TrashItemInput($itemId));
        $this->assertRefused('not_found', fn (): InboxItem => $this->writes()->done(new MarkItemDoneInput($itemId)));
        self::assertNull(self::getContainer()->get(InboxItems::class)->find($itemId));

        self::assertFalse($this->writes()->restore(new RestoreItemInput($itemId))->isTrashed());
        self::assertSame(ItemState::Done, $this->writes()->done(new MarkItemDoneInput($itemId))->state());
    }

    public function testTheTrashIsEmptiedAfter30DaysWithTheMailUnlessARecordUsesIt(): void
    {
        $person = $this->contact('person', 'Client A', self::CLIENT);
        $plain = $this->receive(InboxMail::plain($this->address, messageId: 'a-1@client-a.example.com'));
        $linked = $this->receive(InboxMail::plain($this->address, messageId: 'a-2@client-a.example.com'));
        $recent = $this->receive(InboxMail::plain($this->address, messageId: 'a-3@client-a.example.com'));
        $this->writes()->link(new LinkItemInput($linked, 'contact', $person));
        $messages = [$this->item($plain)->mailMessageId(), $this->item($linked)->mailMessageId()];
        $this->writes()->trash(new TrashItemInput($plain));
        $this->writes()->trash(new TrashItemInput($linked));
        $this->clock->modify('+20 days');
        $this->writes()->trash(new TrashItemInput($recent));
        $this->clock->modify('+11 days');

        self::assertSame(2, self::getContainer()->get(PurgeTrashedItems::class)->run($this->clock->now()));

        self::assertSame([$recent], $this->em->getConnection()->fetchFirstColumn('SELECT id FROM inbox_item'));
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM inbox_item_output'));
        self::assertSame('2026-12-26 10:00:00', $this->purgeAfter($messages[0]), 'Removed with the item.');
        self::assertNull($this->purgeAfter($messages[1]), 'Kept: the contact\'s timeline still shows the e-mail.');
    }

    public function testTheMarkdownAndLinksOfAMailShowAsTextOnTheActivity(): void
    {
        $text = "# Urgent\r\nPay [here](https://pay.example.com/now) or at https://bank.example.com/x and www.example.com.\r\n* <b>bold</b> _x_\r\nWrite to someone@example.com.\r\n";
        $itemId = $this->receive(InboxMail::plain($this->address, body: $text));
        $this->writes()->link(new LinkItemInput($itemId, 'contact', $this->contact('person', 'Client A', self::CLIENT)));
        $body = $this->em->getConnection()->fetchOne('SELECT body FROM activities_activity WHERE id = ?', [$this->item($itemId)->emailActivityId()]);
        self::assertIsString($body);

        $html = $this->rendered($body);

        self::assertStringNotContainsString('<a', $html, 'No link of the mail, not even its sender.');
        self::assertStringNotContainsString('<h', $html);
        self::assertStringNotContainsString('<li', $html);
        self::assertStringNotContainsString('<em', $html);
        self::assertStringNotContainsString('<b>', $html);
        self::assertStringContainsString('# Urgent', $html);
        self::assertStringContainsString('[here](https://pay.example.com/now)', $html);
        self::assertStringContainsString('https://bank.example.com/x', $html);
        self::assertStringContainsString('From: client.a&#64;client-a.example.com', $html, 'The sanitizer writes @ as an entity.');
        self::assertStringContainsString('&lt;b&gt;bold&lt;/b&gt; _x_', $html);
    }

    public function testTheEmailActivityIsTheMailCutToWhatAnActivityHolds(): void
    {
        $itemId = $this->receive(InboxMail::plain($this->address, subject: str_repeat('Long subject ', 30), body: str_repeat('x', 25000)));

        $this->writes()->link(new LinkItemInput($itemId, 'contact', $this->contact('person', 'Client A', self::CLIENT)));

        $activity = $this->em->getConnection()->fetchAssociative('SELECT subject, body FROM activities_activity WHERE id = ?', [$this->item($itemId)->emailActivityId()]);
        self::assertIsArray($activity);
        self::assertSame(rtrim(mb_substr(str_repeat('Long subject ', 30), 0, EmailActivity::MAX_SUBJECT)), $activity['subject']);
        self::assertIsString($activity['body']);
        self::assertSame(EmailActivity::MAX_BODY, mb_strlen($activity['body']));
        self::assertStringEndsWith(EmailActivity::CUT, $activity['body']);
    }

    /**
     * A body as the activity screen renders it: {{ activity.body|markdown }} is Markdown::toHtml().
     */
    private function rendered(string $body): string
    {
        return self::getContainer()->get(Markdown::class)->toHtml($body);
    }

    private function purgeAfter(string $messageId): ?string
    {
        $value = $this->em->getConnection()->fetchOne('SELECT purge_after FROM mail_message WHERE id = ?', [$messageId]);

        return \is_string($value) ? substr($value, 0, 19) : null;
    }

    /**
     * @return list<string>
     */
    private function links(string $activityId): array
    {
        $names = [];
        foreach ($this->em->getConnection()->fetchAllAssociative('SELECT kind, target_id FROM activities_activity_link WHERE activity_id = ?', [$activityId]) as $row) {
            self::assertIsString($row['kind']);
            self::assertIsString($row['target_id']);
            $names[] = $row['kind'].':'.$row['target_id'];
        }

        return $names;
    }

    private function rows(string $sql): int
    {
        $count = $this->em->getConnection()->fetchOne($sql);

        return is_numeric($count) ? (int) $count : -1;
    }
}
