<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Sales\Application;

use Foresi\Sales\Application\Api\ArchiveDealStageInput;
use Foresi\Sales\Application\Api\ArchiveLostReasonInput;
use Foresi\Sales\Application\Api\ChangeDealStageInput;
use Foresi\Sales\Application\Api\CreateDealStageInput;
use Foresi\Sales\Application\Api\CreateLostReasonInput;
use Foresi\Sales\Application\Api\LoseDealInput;
use Foresi\Sales\Application\Api\ReorderDealStagesInput;
use Foresi\Sales\Application\Api\TrashDealInput;
use Foresi\Sales\Application\Api\UpdateDealStageInput;
use Foresi\Sales\Application\Api\UpdateLostReasonInput;
use Foresi\Sales\Application\Api\WinDealInput;
use Foresi\Sales\Application\LostReasonNames;
use Foresi\Sales\Application\StageNames;
use Foresi\Sales\Domain\LostReason;
use Foresi\Sales\Domain\Stage;
use Foresi\Tests\Sales\Support\SalesTestCase;

/**
 * The stages deals go through and the reasons they are lost for, kept in
 * positions 0 to n-1 among those not archived.
 */
final class StagesTest extends SalesTestCase
{
    public function testReorderKeepsPositionsDense(): void
    {
        ['inquiry' => $inquiry, 'meeting' => $meeting, 'proposal' => $proposal, 'negotiation' => $negotiation] = $this->stages;

        $ordered = $this->stageWrites()->reorder(new ReorderDealStagesInput([$proposal->id(), $inquiry->id(), $negotiation->id(), $meeting->id()]));

        self::assertSame([$proposal->id(), $inquiry->id(), $negotiation->id(), $meeting->id()], $this->ids($ordered));
        self::assertSame([[$proposal->id(), 0], [$inquiry->id(), 1], [$negotiation->id(), 2], [$meeting->id(), 3]], $this->positions());
        // A new deal starts at the first stage of the new order.
        self::assertSame($proposal->id(), $this->deal()->stageId());

        $this->assertRefused('order_mismatch', fn (): array => $this->stageWrites()->reorder(new ReorderDealStagesInput([$proposal->id(), $inquiry->id(), $negotiation->id()])));
        $this->assertRefused('order_mismatch', fn (): array => $this->stageWrites()->reorder(new ReorderDealStagesInput([$proposal->id(), $inquiry->id(), $negotiation->id(), $negotiation->id()])));
        $this->assertRefused('order_mismatch', fn (): array => $this->stageWrites()->reorder(new ReorderDealStagesInput([$proposal->id(), $inquiry->id(), $negotiation->id(), $meeting->id(), 7])));

        // Archiving closes the gap, and a new stage comes last.
        $this->stageWrites()->archive(new ArchiveDealStageInput($inquiry->id()));
        $site = $this->stageWrites()->create(new CreateDealStageInput('Site visit'));
        self::assertSame([[$proposal->id(), 0], [$negotiation->id(), 1], [$meeting->id(), 2], [$site->id(), 3]], $this->positions());
        $this->assertRefused('order_mismatch', fn (): array => $this->stageWrites()->reorder(new ReorderDealStagesInput([$proposal->id(), $negotiation->id(), $meeting->id(), $site->id(), $inquiry->id()])));
        self::assertSame([$proposal->id(), $negotiation->id(), $meeting->id(), $site->id(), $inquiry->id()], $this->ids($this->reads()->stages(includeArchived: true)), 'The archived ones come after the others.');
    }

    public function testArchiveRefusedWithOpenDeals(): void
    {
        $meeting = $this->stages['meeting'];
        $deal = $this->deal(stageId: $meeting->id());

        $refused = $this->assertRefused('stage_has_open_deals', fn (): Stage => $this->stageWrites()->archive(new ArchiveDealStageInput($meeting->id())));
        self::assertSame(409, $refused->kind()->status());
        self::assertFalse($this->reload(Stage::class, $meeting->id())->isArchived());

        // A won deal, a lost one and one in the trash do not hold the stage.
        $won = $this->deal(stageId: $meeting->id());
        $this->deals()->win(new WinDealInput($won->id()));
        $lost = $this->deal(stageId: $meeting->id());
        $this->deals()->lose(new LoseDealInput($lost->id()));
        $this->deals()->changeStage(new ChangeDealStageInput($deal->id(), $this->stages['proposal']->id()));
        $trashed = $this->deal(stageId: $meeting->id());
        $this->deals()->trash(new TrashDealInput($trashed->id()));

        $this->clock->modify('+1 hour');
        $archived = $this->stageWrites()->archive(new ArchiveDealStageInput($meeting->id()));
        self::assertTrue($archived->isArchived());
        self::assertEquals(new \DateTimeImmutable('2026-11-23 11:00:00 UTC'), $archived->archivedAt());
        self::assertNotContains($meeting->id(), $this->ids($this->reads()->stages()));
        // Again changes nothing.
        self::assertEquals(new \DateTimeImmutable('2026-11-23 11:00:00 UTC'), $this->stageWrites()->archive(new ArchiveDealStageInput($meeting->id()))->archivedAt());
        $this->assertRefused('not_found', fn (): Stage => $this->stageWrites()->archive(new ArchiveDealStageInput('0192f1a4-0000-7000-8000-000000000000')));
        self::assertSame($meeting->id(), $this->reads()->deal($won->id())->stage?->id(), 'A closed deal keeps its archived stage.');
    }

    public function testStageNamesAndKeys(): void
    {
        $translator = self::getContainer()->get('translator');
        $names = new StageNames($translator);
        $translator->setLocale('cs');

        // A stage that came with the module shows its key's name in the person's language until renamed.
        $seeded = $this->stageWrites()->update(new UpdateDealStageInput($this->stages['proposal']->id(), null));
        self::assertNull($seeded->name());
        self::assertSame('Nabídka', $names->of($seeded));
        $renamed = $this->stageWrites()->update(new UpdateDealStageInput($seeded->id(), '  Written offer '));
        self::assertSame('Written offer', $names->of($renamed));

        $own = $this->stageWrites()->create(new CreateDealStageInput('Site visit'));
        self::assertNull($own->key());
        $this->assertRefused('name_required', fn (): Stage => $this->stageWrites()->update(new UpdateDealStageInput($own->id(), null)));
        $this->assertRefused('name_required', fn (): Stage => $this->stageWrites()->update(new UpdateDealStageInput($own->id(), '  ')));
        $this->assertRefused('name_too_long', fn (): Stage => $this->stageWrites()->update(new UpdateDealStageInput($own->id(), str_repeat('a', Stage::MAX_NAME + 1))));
        $this->assertRefused('not_found', fn (): Stage => $this->stageWrites()->update(new UpdateDealStageInput('not-an-id', 'Other')));
        $this->assertRefused('key_taken', fn (): Stage => $this->stageWrites()->create(new CreateDealStageInput('Other', 'proposal')));
        $this->assertRefused('key_invalid', fn (): Stage => $this->stageWrites()->create(new CreateDealStageInput('Other', 'Site Visit')));
        self::assertCount(5, $this->reads()->stages());
    }

    public function testLostReasonsAreKeptAndArchived(): void
    {
        $translator = self::getContainer()->get('translator');
        $names = new LostReasonNames($translator);
        $translator->setLocale('en');
        ['price' => $price, 'timing' => $timing] = $this->reasons;
        $budget = $this->reasonWrites()->create(new CreateLostReasonInput('Budget cut'));
        self::assertSame(2, $budget->position());
        self::assertSame('Budget cut', $names->of($budget));
        self::assertSame('Price', $names->of($this->reasonWrites()->update(new UpdateLostReasonInput($price->id(), null))));
        self::assertSame('Too expensive', $names->of($this->reasonWrites()->update(new UpdateLostReasonInput($price->id(), 'Too expensive'))));
        $this->assertRefused('key_taken', fn (): LostReason => $this->reasonWrites()->create(new CreateLostReasonInput('Other', 'timing')));
        $this->assertRefused('name_required', fn (): LostReason => $this->reasonWrites()->update(new UpdateLostReasonInput($budget->id(), null)));
        $this->assertRefused('not_found', fn (): LostReason => $this->reasonWrites()->update(new UpdateLostReasonInput('not-an-id', 'Other')));

        $deal = $this->deal();
        $this->deals()->lose(new LoseDealInput($deal->id(), $price->id()));
        $this->clock->modify('+1 day');
        $archived = $this->reasonWrites()->archive(new ArchiveLostReasonInput($price->id()));
        self::assertTrue($archived->isArchived());
        self::assertEquals(new \DateTimeImmutable('2026-11-24 10:00:00 UTC'), $archived->archivedAt());
        // Again changes nothing.
        $this->clock->modify('+1 day');
        self::assertEquals(new \DateTimeImmutable('2026-11-24 10:00:00 UTC'), $this->reasonWrites()->archive(new ArchiveLostReasonInput($price->id()))->archivedAt());
        $this->assertRefused('not_found', fn (): LostReason => $this->reasonWrites()->archive(new ArchiveLostReasonInput('not-an-id')));

        self::assertSame([[$timing->id(), 0], [$budget->id(), 1]], array_map(static fn (LostReason $reason): array => [$reason->id(), $reason->position()], $this->reads()->lostReasons()));
        self::assertSame([$timing->id(), $budget->id(), $price->id()], array_map(static fn (LostReason $reason): string => $reason->id(), $this->reads()->lostReasons(includeArchived: true)));
        // The deal lost for it keeps it.
        self::assertSame($price->id(), $this->reads()->deal($deal->id())->lostReason?->id());
    }

    /**
     * @param list<Stage> $stages
     *
     * @return list<string>
     */
    private function ids(array $stages): array
    {
        return array_map(static fn (Stage $stage): string => $stage->id(), $stages);
    }

    /**
     * @return list<array{string, int}> the stages not archived, as stored
     */
    private function positions(): array
    {
        $this->em->clear();

        return array_map(static fn (Stage $stage): array => [$stage->id(), $stage->position()], $this->reads()->stages());
    }
}
