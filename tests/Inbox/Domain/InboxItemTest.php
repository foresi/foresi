<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Domain;

use Foresi\Inbox\Domain\InboxItem;
use Foresi\Inbox\Domain\ItemState;
use Foresi\Inbox\Domain\MatchBasis;
use Foresi\Inbox\Domain\ThreadKey;
use Foresi\Shared\Domain\Id;
use PHPUnit\Framework\TestCase;

final class InboxItemTest extends TestCase
{
    public function testANewItemIsNewSinceItArrivedInUtc(): void
    {
        $item = $this->item(new \DateTimeImmutable('2026-11-24 09:30:00', new \DateTimeZone('Europe/Prague')));

        self::assertSame(ItemState::New, $item->state());
        self::assertSame('2026-11-24 08:30:00 UTC', $item->receivedAt()->format('Y-m-d H:i:s T'));
        self::assertEquals($item->receivedAt(), $item->stateChangedAt());
        self::assertNull($item->stateChangedBy());
        self::assertTrue($item->isOwnedBy('0192f1a4-0000-7000-8000-00000000000a'));
        self::assertFalse($item->isOwnedBy('0192f1a4-0000-7000-8000-00000000000b'));
        self::assertFalse($item->isOwnedBy(null));
    }

    public function testAForwardAndOnlyAForwardNamesItsOriginalSender(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new InboxItem(Id::generate(), Id::generate(), Id::generate(), new \DateTimeImmutable(), false, 'client.a@example.com', ThreadKey::of([], null, null, 'x'), null);
    }

    public function testTheStateChangesOnceAndRecordsWhoChangedIt(): void
    {
        $item = $this->item();
        $by = Id::generate();
        $now = new \DateTimeImmutable('2026-11-25 10:00:00', new \DateTimeZone('UTC'));

        self::assertTrue($item->changeState(ItemState::Done, $by, $now));
        self::assertFalse($item->changeState(ItemState::Done, Id::generate(), $now->modify('+1 hour')));
        self::assertSame(ItemState::Done, $item->state());
        self::assertSame($by, $item->stateChangedBy());
        self::assertEquals($now, $item->stateChangedAt());
    }

    public function testMatchAndUnmatch(): void
    {
        $item = $this->item();
        $person = Id::generate();
        $company = Id::generate();

        $item->match($person, $company, MatchBasis::Address);
        self::assertSame([$person, $company, MatchBasis::Address], [$item->contactId(), $item->companyId(), $item->matchBasis()]);

        $item->unmatch();
        self::assertSame([null, null, null], [$item->contactId(), $item->companyId(), $item->matchBasis()]);
    }

    public function testTrashAndRestoreAreIdempotent(): void
    {
        $item = $this->item();
        $by = Id::generate();

        self::assertTrue($item->trash($by, new \DateTimeImmutable()));
        self::assertFalse($item->trash(Id::generate(), new \DateTimeImmutable()));
        self::assertSame($by, $item->trashedBy());
        self::assertTrue($item->restore());
        self::assertFalse($item->restore());
        self::assertFalse($item->isTrashed());
        self::assertNull($item->trashedBy());
    }

    public function testOneEmailActivityPerItem(): void
    {
        $item = $this->item();
        $activity = Id::generate();
        $item->recordEmailActivity($activity);
        $item->recordEmailActivity($activity);

        self::assertSame($activity, $item->emailActivityId());
        $this->expectException(\LogicException::class);
        $item->recordEmailActivity(Id::generate());
    }

    public function testAPurposeKeyHasOneToFortyCharacters(): void
    {
        $make = static function (string $purpose): InboxItem {
            $id = Id::generate();

            return new InboxItem($id, Id::generate(), Id::generate(), new \DateTimeImmutable(), false, null, ThreadKey::of([], null, 'm@example.com', $id), $purpose);
        };

        self::assertSame(str_repeat('a', 40), $make(str_repeat('a', 40))->duplicateOfPurpose());
        foreach (['', str_repeat('a', 41)] as $refused) {
            try {
                $make($refused);
                self::fail(\sprintf('The purpose "%s" should be refused.', $refused));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAContactAndItsCompanyAreNamedByTheirIds(): void
    {
        $item = $this->item();

        foreach ([['not-an-id', null], [Id::generate(), 'not-an-id']] as [$contact, $company]) {
            try {
                $item->match($contact, $company, MatchBasis::Manual);
                self::fail('A contact is named by its id.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertNull($item->contactId());
    }

    private function item(?\DateTimeImmutable $receivedAt = null): InboxItem
    {
        $id = Id::generate();

        return new InboxItem($id, Id::generate(), '0192f1a4-0000-7000-8000-00000000000a', $receivedAt ?? new \DateTimeImmutable(), false, null, ThreadKey::of([], null, 'm@example.com', $id), null);
    }
}
