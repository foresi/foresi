<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Sales\Application;

use Foresi\Sales\Application\Api\AddDealLineInput;
use Foresi\Sales\Application\Api\ArchiveDealStageInput;
use Foresi\Sales\Application\Api\ChangeDealStageInput;
use Foresi\Sales\Application\Api\CreateDealInput;
use Foresi\Sales\Application\Api\CreateFollowUpDealInput;
use Foresi\Sales\Application\Api\LoseDealInput;
use Foresi\Sales\Application\Api\RestoreDealInput;
use Foresi\Sales\Application\Api\TrashDealInput;
use Foresi\Sales\Application\Api\UpdateDealInput;
use Foresi\Sales\Application\Api\WinDealInput;
use Foresi\Sales\Domain\Deal;
use Foresi\Sales\Domain\DealLine;
use Foresi\Sales\Domain\DealLink;
use Foresi\Tests\Sales\Support\SalesTestCase;

/**
 * The deal lists with their filters, one deal with what is kept beside it,
 * and the trash.
 */
final class DealReadsTest extends SalesTestCase
{
    public function testListsFilterByStatusStageOwnerAndClient(): void
    {
        $beta = $this->company('Beta Example a.s.');
        $audit = $this->deal('Roof audit');
        $this->clock->modify('+1 minute');
        $gutter = $this->deals()->create(new CreateDealInput('Gutter audit', $beta->id(), $this->stages['proposal']->id(), $this->member->user()->id()));
        $this->clock->modify('+1 minute');
        $won = $this->deal('Chimney audit');
        $this->deals()->win(new WinDealInput($won->id()));

        self::assertSame(['Chimney audit', 'Gutter audit', 'Roof audit'], $this->titles($this->reads()->deals()), 'The newest first.');
        self::assertSame(['Gutter audit', 'Roof audit'], $this->titles($this->reads()->deals('open')));
        self::assertSame(['Chimney audit'], $this->titles($this->reads()->deals('won')));
        self::assertSame([], $this->reads()->deals('lost'));
        self::assertSame(['Gutter audit'], $this->titles($this->reads()->deals(stageId: $this->stages['proposal']->id())));
        self::assertSame(['Gutter audit'], $this->titles($this->reads()->deals(ownerUserId: $this->member->user()->id())));
        self::assertSame(['Chimney audit', 'Roof audit'], $this->titles($this->reads()->deals(clientPartyId: $this->client->id())));
        self::assertSame(['Roof audit'], $this->titles($this->reads()->deals('open', $this->stages['inquiry']->id(), $this->owner->user()->id(), $this->client->id())));
        self::assertSame(['Gutter audit'], $this->titles($this->reads()->dealsOfClient($beta->id())));
        $refused = $this->assertRefused('status_unknown', fn (): array => $this->reads()->deals('closed'));
        self::assertSame('status', $refused->field());
        self::assertSame($audit->id(), $this->reads()->deals('open', clientPartyId: $this->client->id())[0]->id());
        self::assertSame($gutter->id(), $this->reads()->deals(ownerUserId: $this->member->user()->id())[0]->id());
    }

    public function testOneDealWithWhatIsKeptBesideIt(): void
    {
        $deal = $this->deal('Roof audit', contactPersonIds: [$this->petra->id()]);
        $this->typedLine($deal, 'Audit', '12000.00');
        $this->lines()->add(new AddDealLineInput($deal->id(), name: 'Travel', unitPrice: '50.00', currency: 'EUR', billingKind: 'recurring', recurrenceUnit: 'week', interval: 1));
        $this->deals()->lose(new LoseDealInput($deal->id(), $this->reasons['timing']->id(), 'Next year.'));

        $detail = $this->reads()->deal($deal->id());

        self::assertSame($deal->id(), $detail->deal->id());
        self::assertSame($this->stages['inquiry']->id(), $detail->stage?->id());
        self::assertSame($this->reasons['timing']->id(), $detail->lostReason?->id());
        self::assertSame(['Audit', 'Travel'], array_map(static fn (DealLine $line): string => $line->name(), $detail->lines));
        self::assertSame(['CZK' => 1_200_000], array_map(static fn ($money): int => $money->minor(), $detail->value->oneOff));
        self::assertSame(['EUR' => 21_667], array_map(static fn ($money): int => $money->minor(), $detail->value->monthly));
        self::assertTrue($detail->value->approximate);
        self::assertSame([], $detail->links);
        self::assertSame([$this->petra->id()], $detail->contactPersonIds);
        self::assertSame(['Petra Example'], array_map(static fn ($person): string => $person->displayName, $detail->contactPersons));

        self::assertNull($this->reads()->deal($this->deal('Open deal')->id())->lostReason);
        $this->assertRefused('not_found', fn (): mixed => $this->reads()->deal('0192f1a4-0000-7000-8000-000000000000'));
        $this->assertRefused('not_found', fn (): mixed => $this->reads()->deal('not-an-id'));
    }

    public function testTrashAndRestore(): void
    {
        $deal = $this->deal();
        $this->clock->modify('+1 hour');

        $trashed = $this->deals()->trash(new TrashDealInput($deal->id()));
        self::assertTrue($trashed->isTrashed());
        self::assertEquals(new \DateTimeImmutable('2026-11-23 11:00:00 UTC'), $trashed->trashedAt());
        self::assertSame($this->owner->user()->id(), $trashed->trashedBy());
        // Again changes nothing and records nothing.
        $this->clock->modify('+1 hour');
        self::assertEquals(new \DateTimeImmutable('2026-11-23 11:00:00 UTC'), $this->deals()->trash(new TrashDealInput($deal->id()))->trashedAt());
        $stage = $this->stages['inquiry']->id();
        self::assertSame([['deal_id' => $deal->id(), 'status' => 'open', 'stage_id' => $stage]], $this->events('sales.deal.trashed'));

        // A deal in the trash leaves the lists, still reads by its id, and takes no change but restore.
        self::assertSame([], $this->reads()->deals());
        self::assertTrue($this->reads()->deal($deal->id())->deal->isTrashed());
        $this->assertRefused('not_found', fn (): Deal => $this->deals()->update(new UpdateDealInput($deal->id(), title: 'Renamed')));
        $this->assertRefused('not_found', fn (): Deal => $this->deals()->changeStage(new ChangeDealStageInput($deal->id(), $this->stages['meeting']->id())));
        $this->assertRefused('not_found', fn (): Deal => $this->deals()->win(new WinDealInput($deal->id())));
        $this->assertRefused('not_found', fn (): Deal => $this->deals()->createFollowUp(new CreateFollowUpDealInput($deal->id(), 'Follow-up')));
        $this->assertRefused('not_found', fn (): DealLine => $this->typedLine($deal));
        $this->assertRefused('not_found', fn (): DealLink => $this->links()->add(new \Foresi\Sales\Application\Api\AddDealLinkInput($deal->id(), $this->deal('Other', stageId: $this->stages['proposal']->id())->id())));
        // The stage it sits in may go: it holds no open deal in the lists.
        $this->stageWrites()->archive(new ArchiveDealStageInput($stage));

        $restored = $this->deals()->restore(new RestoreDealInput($deal->id()));
        self::assertFalse($restored->isTrashed());
        self::assertNull($restored->trashedBy());
        self::assertNull($restored->trashedAt());
        self::assertFalse($this->deals()->restore(new RestoreDealInput($deal->id()))->isTrashed());
        self::assertSame([['deal_id' => $deal->id(), 'status' => 'open', 'stage_id' => $stage]], $this->events('sales.deal.restored'));
        self::assertEquals(new \DateTimeImmutable('2026-11-23 12:00:00 UTC'), $this->reload(Deal::class, $deal->id())->updatedAt());

        $this->assertRefused('not_found', fn (): Deal => $this->deals()->trash(new TrashDealInput('0192f1a4-0000-7000-8000-000000000000')));
        $this->assertRefused('not_found', fn (): Deal => $this->deals()->restore(new RestoreDealInput('not-an-id')));
    }

    public function testEveryStageArchivedLeavesNoStageForANewDeal(): void
    {
        foreach ($this->stages as $stage) {
            $this->stageWrites()->archive(new ArchiveDealStageInput($stage->id()));
        }

        $refused = $this->assertRefused('no_stage', fn (): Deal => $this->deal());
        self::assertSame(409, $refused->kind()->status());
    }

    /**
     * @param list<Deal> $deals
     *
     * @return list<string>
     */
    private function titles(array $deals): array
    {
        return array_map(static fn (Deal $deal): string => $deal->title(), $deals);
    }
}
