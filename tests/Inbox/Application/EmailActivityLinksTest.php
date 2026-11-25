<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Application;

use Foresi\Activities\Contract\ActivityRecorder;
use Foresi\Activities\Contract\LinkableRecords;
use Foresi\Activities\Contract\RecordedActivity;
use Foresi\Inbox\Application\EmailActivity;
use Foresi\Inbox\Domain\InboxItem;
use Foresi\Inbox\Domain\MatchBasis;
use Foresi\Inbox\Domain\ThreadKey;
use Foresi\Instance\Application\ConfiguredZone;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Instance\Application\ZoneFallback;
use Foresi\Mail\Contract\MailMessageView;
use Foresi\Mail\Contract\Messages;
use Foresi\Shared\Domain\Id;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The records the one e-mail activity is linked to when an output makes it, and what it says
 * when the mail is not there any more.
 */
final class EmailActivityLinksTest extends TestCase
{
    private ?RecordedActivity $recorded = null;

    public function testTheMatchedContactAndTheOutputAreLinkedOnceEachAsAList(): void
    {
        $person = Id::generate();
        $company = Id::generate();
        $item = $this->item();
        $item->match($person, $company, MatchBasis::Address);

        $this->activity($item, $this->message())->link($item, 'contact', $person);

        self::assertSame(
            [['kind' => 'contact', 'id' => $person], ['kind' => 'contact', 'id' => $company]],
            $this->recorded()->links,
            'The person is the output as well, and is linked once.',
        );
    }

    public function testAnOutputOfAnotherKindIsLinkedAfterTheContacts(): void
    {
        $person = Id::generate();
        $record = Id::generate();
        $item = $this->item();
        $item->match($person, null, MatchBasis::Manual);

        $this->activity($item, $this->message())->link($item, 'task', $record);

        self::assertSame([['kind' => 'contact', 'id' => $person], ['kind' => 'task', 'id' => $record]], $this->recorded()->links);
    }

    public function testARecordNoModuleOffersIsLeftOut(): void
    {
        $record = Id::generate();
        $item = $this->item();

        $this->activity($item, $this->message(), linkable: false)->link($item, 'task', $record);

        self::assertSame([], $this->recorded()->links);
    }

    public function testAMailMailRemovedStillGivesAnActivityNamedAfterNothing(): void
    {
        $item = $this->item();

        $this->activity($item, null)->link($item, 'task', Id::generate());

        self::assertSame('E-mail', $this->recorded()->subject);
        self::assertSame("From: \nDate: 2026-11-25 11:00\n\n", $this->recorded()->body);
    }

    public function testAMailWithoutTextGivesAnActivityWithoutText(): void
    {
        $item = $this->item();

        $this->activity($item, $this->message(), text: null)->link($item, 'task', Id::generate());

        self::assertSame('Offer', $this->recorded()->subject);
        self::assertSame("From: client\\.a\\@client\\-a\\.example\\.com\nDate: 2026-11-25 11:00\n\n", $this->recorded()->body);
    }

    private function recorded(): RecordedActivity
    {
        self::assertInstanceOf(RecordedActivity::class, $this->recorded);

        return $this->recorded;
    }

    private function item(): InboxItem
    {
        $id = Id::generate();

        return new InboxItem($id, Id::generate(), Id::generate(), new \DateTimeImmutable('2026-11-25 10:00:00', new \DateTimeZone('UTC')), false, null, ThreadKey::of([], null, 'm@example.com', $id), null);
    }

    private function message(): MailMessageView
    {
        return new MailMessageView(Id::generate(), Id::generate(), 'inbox', Id::generate(), 'webhook', null, null, [], 'client.a@client-a.example.com', 'Offer', null, new \DateTimeImmutable('2026-11-25 10:00:00', new \DateTimeZone('UTC')), 'verified', str_repeat('a', 64), 100);
    }

    private function activity(InboxItem $item, ?MailMessageView $message, bool $linkable = true, ?string $text = 'Hello'): EmailActivity
    {
        $records = self::createStub(LinkableRecords::class);
        $records->method('canLink')->willReturn($linkable);
        $messages = self::createStub(Messages::class);
        $messages->method('find')->willReturn($message);
        $messages->method('text')->willReturn($text);
        $recorder = self::createStub(ActivityRecorder::class);
        $recorder->method('record')->willReturnCallback(function (RecordedActivity $activity): string {
            $this->recorded = $activity;

            return Id::generate();
        });
        $zone = new class implements ZoneFallback {
            public function zone(): ?\DateTimeZone
            {
                return null;
            }
        };

        return new EmailActivity($recorder, $records, $messages, new InstanceClock(new ConfiguredZone('Europe/Prague'), $zone, new MockClock('2026-11-25 10:00:00', 'UTC')));
    }
}
