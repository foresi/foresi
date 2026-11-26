<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Application;

use Foresi\Activities\Contract\LinkableRecords;
use Foresi\Inbox\Application\Api\LinkItemInput;
use Foresi\Inbox\Application\Api\TrashItemInput;
use Foresi\Inbox\Application\InboxTransaction;
use Foresi\Inbox\Application\ItemStore;
use Foresi\Inbox\Application\PurgeItemTrashDaily;
use Foresi\Inbox\Application\PurgeTrashedItems;
use Foresi\Inbox\Application\SuggestionStore;
use Foresi\Mail\Contract\MessageRetention;
use Foresi\Tests\Inbox\Support\InboxMail;
use Foresi\Tests\Inbox\Support\InboxTestCase;

/**
 * Emptying the inbox trash: the daily job that does it, and what happens to the mail of each item.
 */
final class PurgeTrashedItemsTest extends InboxTestCase
{
    public function testTheDailyJobEmptiesTheTrashOfWhatIsOlderThan30Days(): void
    {
        $old = $this->receive(InboxMail::plain($this->address, messageId: 'a-1@client-a.example.com'));
        $this->writes()->trash(new TrashItemInput($old));
        $this->clock->modify('+31 days');
        $recent = $this->receive(InboxMail::plain($this->address, messageId: 'a-2@client-a.example.com'));
        $this->writes()->trash(new TrashItemInput($recent));

        $job = new PurgeItemTrashDaily(self::getContainer()->get(PurgeTrashedItems::class), $this->clock);
        $job->run(null);

        self::assertSame('inbox-trash-purge', $job->name());
        self::assertSame([$recent], $this->em->getConnection()->fetchFirstColumn('SELECT id FROM inbox_item'));
    }

    public function testTheMailOfAnItemARecordStillUsesIsKeptAndTheOthersAreReleased(): void
    {
        $person = $this->contact('person', 'Client A', self::CLIENT);
        $plain = $this->receive(InboxMail::plain($this->address, messageId: 'a-1@client-a.example.com'));
        $linked = $this->receive(InboxMail::plain($this->address, messageId: 'a-2@client-a.example.com'));
        $this->writes()->link(new LinkItemInput($linked, 'contact', $person));
        $messages = ['plain' => $this->item($plain)->mailMessageId(), 'linked' => $this->item($linked)->mailMessageId()];
        $this->writes()->trash(new TrashItemInput($plain));
        $this->writes()->trash(new TrashItemInput($linked));
        $this->clock->modify('+31 days');
        $retention = new class implements MessageRetention {
            /** @var list<string> */
            public array $kept = [];
            /** @var list<string> */
            public array $released = [];

            public function release(string $messageId, \DateTimeImmutable $after): void
            {
                $this->released[] = $messageId;
            }

            public function keep(string $messageId): void
            {
                $this->kept[] = $messageId;
            }
        };
        $c = self::getContainer();

        new PurgeTrashedItems($c->get(ItemStore::class), $c->get(InboxTransaction::class), $retention, $c->get(LinkableRecords::class), $c->get(SuggestionStore::class))->run($this->clock->now());

        self::assertSame([$messages['linked']], $retention->kept);
        self::assertSame([$messages['plain']], $retention->released);
    }
}
