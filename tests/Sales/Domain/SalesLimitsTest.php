<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Sales\Domain;

use Foresi\Sales\Domain\Deal;
use Foresi\Sales\Domain\InvalidField;
use Foresi\Sales\Domain\LostReason;
use Foresi\Sales\Domain\Stage;
use Foresi\Shared\Domain\Id;
use PHPUnit\Framework\TestCase;

/**
 * The edges of a deal, a stage and a lost reason: lengths count characters,
 * not bytes, and the limit itself is allowed; every change of a deal moves
 * its updated time; archiving says whether it archived now.
 */
final class SalesLimitsTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-23 10:00:00 UTC');
    }

    public function testADealsLengthsCountCharactersAndTheLimitIsAllowed(): void
    {
        $deal = $this->deal(str_repeat('č', Deal::MAX_TITLE), str_repeat('č', Deal::MAX_DESCRIPTION));
        self::assertSame([Deal::MAX_TITLE, Deal::MAX_DESCRIPTION], [mb_strlen($deal->title()), mb_strlen($deal->description())]);

        $this->assertRefused('title', 'title_too_long', 'The title is at most 200 characters.', fn (): Deal => $this->deal(str_repeat('č', Deal::MAX_TITLE + 1)));
        $this->assertRefused('description', 'description_too_long', 'The description is at most 10000 characters.', fn (): Deal => $this->deal('Roof audit', str_repeat('č', Deal::MAX_DESCRIPTION + 1)));

        $deal->lose(null, str_repeat('č', Deal::MAX_LOST_NOTE), $this->now);
        self::assertSame(Deal::MAX_LOST_NOTE, mb_strlen((string) $deal->lostNote()));
        $open = $this->deal('Roof audit');
        $this->assertRefused('note', 'lost_note_too_long', 'The note is at most 1000 characters.', fn () => $open->lose(null, str_repeat('č', Deal::MAX_LOST_NOTE + 1), $this->now));
        self::assertTrue($open->isOpen());
    }

    public function testEveryChangeOfADealMovesItsUpdatedTime(): void
    {
        $deal = $this->deal('Roof audit');
        self::assertEquals($this->now, $deal->updatedAt());

        $changes = [
            'describe' => fn (\DateTimeImmutable $at) => $deal->describe('Roof audit, phase 2', '', $at),
            'assignTo' => fn (\DateTimeImmutable $at) => $deal->assignTo(Id::generate(), $at),
            'changeClient' => fn (\DateTimeImmutable $at) => $deal->changeClient(Id::generate(), $at),
            'moveTo' => fn (\DateTimeImmutable $at): bool => $deal->moveTo(Id::generate(), $at),
            'win' => $deal->win(...),
            'reopen after win' => $deal->reopen(...),
            'lose' => fn (\DateTimeImmutable $at) => $deal->lose(null, 'Too expensive', $at),
            'reopen after loss' => $deal->reopen(...),
            'trash' => fn (\DateTimeImmutable $at): bool => $deal->trash(Id::generate(), $at),
            'restore' => $deal->restore(...),
        ];
        $minutes = 0;
        foreach ($changes as $change => $apply) {
            $at = $this->now->modify(\sprintf('+%d minutes', ++$minutes));
            $apply($at);
            self::assertEquals($at, $deal->updatedAt(), $change);
        }
    }

    public function testAStageAndALostReasonCountCharactersOfTheirName(): void
    {
        $stage = new Stage(null, str_repeat('č', Stage::MAX_NAME), 1, $this->now);
        $reason = new LostReason(null, str_repeat('č', LostReason::MAX_NAME), 1, $this->now);
        self::assertSame([Stage::MAX_NAME, LostReason::MAX_NAME], [mb_strlen((string) $stage->name()), mb_strlen((string) $reason->name())]);

        $this->assertRefused('name', 'name_too_long', 'A name is at most 80 characters.', fn (): Stage => new Stage(null, str_repeat('č', Stage::MAX_NAME + 1), 1, $this->now));
        $this->assertRefused('name', 'name_too_long', 'A name is at most 80 characters.', fn () => $reason->rename(str_repeat('č', LostReason::MAX_NAME + 1)));
    }

    public function testArchivingSaysWhetherItArchivedNowAndKeepsTheFirstTime(): void
    {
        $later = $this->now->modify('+1 day');
        foreach ([new Stage('inquiry', null, 1, $this->now), new LostReason('price', null, 1, $this->now)] as $kept) {
            self::assertFalse($kept->isArchived());
            self::assertTrue($kept->archive($this->now));
            self::assertFalse($kept->archive($later));
            self::assertEquals($this->now, $kept->archivedAt());
        }
    }

    private function deal(string $title, string $description = ''): Deal
    {
        return new Deal($title, $description, Id::generate(), Id::generate(), Id::generate(), $this->now);
    }

    private function assertRefused(string $field, string $reason, string $message, \Closure $act): void
    {
        try {
            $act();
            self::fail(\sprintf('The %s was accepted.', $field));
        } catch (InvalidField $refused) {
            self::assertSame([$field, $reason, $message], [$refused->field, $refused->reason, $refused->getMessage()]);
        }
    }
}
