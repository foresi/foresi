<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Sales\Application;

use Foresi\Sales\Application\Api\ChangeDealStageInput;
use Foresi\Sales\Application\Api\CreateDealInput;
use Foresi\Sales\Application\Api\LoseDealInput;
use Foresi\Sales\Application\Api\ReopenDealInput;
use Foresi\Sales\Application\Api\UpdateDealInput;
use Foresi\Sales\Application\Api\WinDealInput;
use Foresi\Sales\Domain\Deal;
use Foresi\Sales\Domain\DealStatus;
use Foresi\Tests\Sales\Support\SalesTestCase;

/**
 * The rules of a deal: its client and contact persons come from the address
 * book, its stage changes only while it is open, and won, lost and reopen
 * follow one another.
 */
final class DealRulesTest extends SalesTestCase
{
    public function testContactPersonMustBelongToClient(): void
    {
        $other = $this->company('Beta Example a.s.');
        $stranger = $this->contactPerson($other, 'Tomas Example');

        $this->assertRefused('contact_person_not_of_client', fn (): Deal => $this->deal(contactPersonIds: [$this->petra->id(), $stranger->id()]));
        // The client itself is not one of its contact persons.
        $refused = $this->assertRefused('contact_person_not_of_client', fn (): Deal => $this->deal(contactPersonIds: [$this->client->id()]));
        self::assertSame('contactPersonIds', $refused->field());
        self::assertSame([], $this->reads()->deals(), 'A refused deal is not kept.');

        $deal = $this->deal(contactPersonIds: [$this->karel->id(), $this->petra->id(), $this->karel->id()]);
        self::assertSame([$this->karel->id(), $this->petra->id()], $this->reads()->deal($deal->id())->contactPersonIds, 'Once each, in the order given.');

        $this->assertRefused('contact_person_not_of_client', fn (): Deal => $this->deals()->update(new UpdateDealInput($deal->id(), contactPersonIds: [$stranger->id()])));
        self::assertSame([$this->karel->id(), $this->petra->id()], $this->reads()->deal($deal->id())->contactPersonIds);
    }

    public function testStageChangeOnlyWhileOpen(): void
    {
        $deal = $this->deal();
        self::assertSame($this->stages['inquiry']->id(), $deal->stageId(), 'A new deal starts at the first stage.');

        $this->deals()->changeStage(new ChangeDealStageInput($deal->id(), $this->stages['proposal']->id()));
        $changed = $this->events('sales.deal.stage_changed');
        self::assertSame([['deal_id' => $deal->id(), 'status' => 'open', 'from_stage_id' => $this->stages['inquiry']->id(), 'to_stage_id' => $this->stages['proposal']->id()]], $changed);
        // To the stage it is at already changes nothing and records nothing.
        $this->deals()->changeStage(new ChangeDealStageInput($deal->id(), $this->stages['proposal']->id()));
        self::assertSame($changed, $this->events('sales.deal.stage_changed'));

        $this->deals()->win(new WinDealInput($deal->id()));
        $refused = $this->assertRefused('deal_not_open', fn (): Deal => $this->deals()->changeStage(new ChangeDealStageInput($deal->id(), $this->stages['meeting']->id())));
        self::assertSame(409, $refused->kind()->status());
        self::assertSame($this->stages['proposal']->id(), $this->reload(Deal::class, $deal->id())->stageId());

        $this->deals()->reopen(new ReopenDealInput($deal->id()));
        $this->deals()->lose(new LoseDealInput($deal->id()));
        $this->assertRefused('deal_not_open', fn (): Deal => $this->deals()->changeStage(new ChangeDealStageInput($deal->id(), $this->stages['meeting']->id())));
        $this->deals()->reopen(new ReopenDealInput($deal->id()));
        $moved = $this->deals()->changeStage(new ChangeDealStageInput($deal->id(), $this->stages['meeting']->id()));
        self::assertSame($this->stages['meeting']->id(), $moved->stageId());
    }

    public function testWinLoseReopenTransitions(): void
    {
        $deal = $this->deal();
        $this->assertRefused('deal_not_closed', fn (): Deal => $this->deals()->reopen(new ReopenDealInput($deal->id())));

        $this->clock->modify('+1 hour');
        $won = $this->deals()->win(new WinDealInput($deal->id()));
        self::assertSame(DealStatus::Won, $won->status());
        self::assertEquals(new \DateTimeImmutable('2026-11-23 11:00:00 UTC'), $won->wonAt());
        $this->assertRefused('deal_not_open', fn (): Deal => $this->deals()->win(new WinDealInput($deal->id())));
        $this->assertRefused('deal_not_open', fn (): Deal => $this->deals()->lose(new LoseDealInput($deal->id())));

        $this->deals()->reopen(new ReopenDealInput($deal->id()));
        $lost = $this->deals()->lose(new LoseDealInput($deal->id(), $this->reasons['price']->id(), '  Too dear for them this year.  '));
        self::assertSame(DealStatus::Lost, $lost->status());
        self::assertEquals(new \DateTimeImmutable('2026-11-23 11:00:00 UTC'), $lost->lostAt());
        self::assertSame($this->reasons['price']->id(), $lost->lostReasonId());
        self::assertSame('Too dear for them this year.', $lost->lostNote());
        $this->assertRefused('deal_not_open', fn (): Deal => $this->deals()->win(new WinDealInput($deal->id())));

        $stage = $this->stages['inquiry']->id();
        self::assertSame([['deal_id' => $deal->id(), 'status' => 'won', 'stage_id' => $stage]], $this->events('sales.deal.won'));
        // The lost event names the reason, never the note.
        self::assertSame([['deal_id' => $deal->id(), 'status' => 'lost', 'stage_id' => $stage, 'lost_reason_id' => $this->reasons['price']->id()]], $this->events('sales.deal.lost'));
        self::assertSame([['deal_id' => $deal->id(), 'status' => 'open', 'stage_id' => $stage]], $this->events('sales.deal.reopened'));
        self::assertSame([['deal_id' => $deal->id(), 'status' => 'open', 'stage_id' => $stage]], $this->events('sales.deal.created'));
    }

    public function testLoseTakesAReasonFromTheListOrNone(): void
    {
        $deal = $this->deal();
        $this->assertRefused('lost_reason_unknown', fn (): Deal => $this->deals()->lose(new LoseDealInput($deal->id(), '0192f1a4-0000-7000-8000-000000000000')));
        $this->reasonWrites()->archive(new \Foresi\Sales\Application\Api\ArchiveLostReasonInput($this->reasons['timing']->id()));
        $this->assertRefused('lost_reason_archived', fn (): Deal => $this->deals()->lose(new LoseDealInput($deal->id(), $this->reasons['timing']->id())));
        $this->assertRefused('lost_note_too_long', fn (): Deal => $this->deals()->lose(new LoseDealInput($deal->id(), null, str_repeat('a', Deal::MAX_LOST_NOTE + 1))));
        self::assertTrue($this->reload(Deal::class, $deal->id())->isOpen());

        $lost = $this->deals()->lose(new LoseDealInput($deal->id(), null, '   '));
        self::assertNull($lost->lostReasonId());
        self::assertNull($lost->lostNote(), 'A blank note is no note.');
        self::assertSame([['deal_id' => $deal->id(), 'status' => 'lost', 'stage_id' => $this->stages['inquiry']->id(), 'lost_reason_id' => null]], $this->events('sales.deal.lost'));
    }

    public function testReopenClearsReasonAndNote(): void
    {
        $deal = $this->deal();
        $this->deals()->lose(new LoseDealInput($deal->id(), $this->reasons['timing']->id(), 'Next year, maybe.'));

        $this->deals()->reopen(new ReopenDealInput($deal->id()));

        $reopened = $this->reload(Deal::class, $deal->id());
        self::assertSame(DealStatus::Open, $reopened->status());
        self::assertNull($reopened->lostAt());
        self::assertNull($reopened->lostReasonId());
        self::assertNull($reopened->lostNote());
        self::assertSame($this->stages['inquiry']->id(), $reopened->stageId(), 'It is open again at the stage it was at.');

        $this->deals()->win(new WinDealInput($deal->id()));
        $this->deals()->reopen(new ReopenDealInput($deal->id()));
        self::assertNull($this->reload(Deal::class, $deal->id())->wonAt());
    }

    public function testArchivedClientRefusesNewDealKeepsOld(): void
    {
        $old = $this->deal('Old roof audit');
        $this->client->archive(new \DateTimeImmutable('2026-11-23 09:00:00'));
        $this->em->flush();

        $refused = $this->assertRefused('client_archived', fn (): Deal => $this->deal('New roof audit'));
        self::assertSame('clientPartyId', $refused->field());

        self::assertSame([$old->id()], array_map(static fn (Deal $deal): string => $deal->id(), $this->reads()->dealsOfClient($this->client->id())));
        self::assertSame('Old roof audit', $this->reads()->deal($old->id())->deal->title());
        // The old deal still goes on: it is won like any other.
        self::assertSame(DealStatus::Won, $this->deals()->win(new WinDealInput($old->id()))->status());

        $this->assertRefused('client_unknown', fn (): Deal => $this->deals()->create(new CreateDealInput('Audit', '0192f1a4-0000-7000-8000-000000000000')));
    }

    public function testOwnerIsAnOwnerOrMember(): void
    {
        $deal = $this->deals()->create(new CreateDealInput('Audit', $this->client->id(), ownerUserId: $this->member->user()->id()));
        self::assertSame($this->member->user()->id(), $deal->ownerUserId());
        self::assertSame($this->owner->user()->id(), $this->deal()->ownerUserId(), 'The caller when left out.');

        $this->assertRefused('owner_not_staff', fn (): Deal => $this->deals()->create(new CreateDealInput('Audit', $this->client->id(), ownerUserId: $this->guest->user()->id())));
        $this->assertRefused('owner_not_staff', fn (): Deal => $this->deals()->update(new UpdateDealInput($deal->id(), ownerUserId: $this->guest->user()->id())));

        $updated = $this->deals()->update(new UpdateDealInput($deal->id(), ownerUserId: $this->owner->user()->id()));
        self::assertSame($this->owner->user()->id(), $updated->ownerUserId());
    }

    public function testStageOfANewDeal(): void
    {
        $deal = $this->deal(stageId: $this->stages['proposal']->id());
        self::assertSame($this->stages['proposal']->id(), $deal->stageId());

        $this->assertRefused('stage_unknown', fn (): Deal => $this->deal(stageId: '0192f1a4-0000-7000-8000-000000000000'));
        $this->stageWrites()->archive(new \Foresi\Sales\Application\Api\ArchiveDealStageInput($this->stages['meeting']->id()));
        $this->assertRefused('stage_archived', fn (): Deal => $this->deal(stageId: $this->stages['meeting']->id()));
        $this->assertRefused('stage_archived', fn (): Deal => $this->deals()->changeStage(new ChangeDealStageInput($deal->id(), $this->stages['meeting']->id())));
        $this->assertRefused('stage_unknown', fn (): Deal => $this->deals()->changeStage(new ChangeDealStageInput($deal->id(), 'not-an-id')));
    }

    public function testTitleAndDescription(): void
    {
        $this->assertRefused('title_required', fn (): Deal => $this->deal('   '));
        $this->assertRefused('title_too_long', fn (): Deal => $this->deal(str_repeat('a', Deal::MAX_TITLE + 1)));
        $this->assertRefused('description_too_long', fn (): Deal => $this->deals()->create(new CreateDealInput('Audit', $this->client->id(), description: str_repeat('a', Deal::MAX_DESCRIPTION + 1))));

        $deal = $this->deals()->create(new CreateDealInput('  Roof audit  ', $this->client->id(), description: '  The **north** wing.  ', contactPersonIds: [$this->petra->id()]));
        self::assertSame('Roof audit', $deal->title());
        self::assertSame('The **north** wing.', $deal->description());

        $this->clock->modify('+1 day');
        $updated = $this->deals()->update(new UpdateDealInput($deal->id(), title: 'Roof and gutter audit'));
        self::assertSame('Roof and gutter audit', $updated->title());
        self::assertSame('The **north** wing.', $updated->description(), 'What is not given stays.');
        self::assertEquals(new \DateTimeImmutable('2026-11-24 10:00:00 UTC'), $updated->updatedAt());
        self::assertSame([$this->petra->id()], $this->reads()->deal($deal->id())->contactPersonIds, 'The contact persons stay when not given.');

        $this->deals()->update(new UpdateDealInput($deal->id(), contactPersonIds: [$this->karel->id(), $this->petra->id()]));
        self::assertSame([$this->karel->id(), $this->petra->id()], $this->reads()->deal($deal->id())->contactPersonIds);
        $this->deals()->update(new UpdateDealInput($deal->id(), contactPersonIds: [$this->petra->id()]));
        self::assertSame([$this->petra->id()], $this->reads()->deal($deal->id())->contactPersonIds);
        $this->deals()->update(new UpdateDealInput($deal->id(), contactPersonIds: []));
        self::assertSame([], $this->reads()->deal($deal->id())->contactPersonIds);

        self::assertSame(
            array_fill(0, 4, ['deal_id' => $deal->id(), 'status' => 'open', 'stage_id' => $this->stages['inquiry']->id()]),
            $this->events('sales.deal.updated'),
        );
    }
}
