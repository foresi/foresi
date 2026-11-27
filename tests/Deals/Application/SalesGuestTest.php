<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Deals\Application;

use Foresi\Deals\Application\Api\AddDealLineInput;
use Foresi\Deals\Application\Api\AddDealLinkInput;
use Foresi\Deals\Application\Api\ArchiveDealStageInput;
use Foresi\Deals\Application\Api\ArchiveLostReasonInput;
use Foresi\Deals\Application\Api\ChangeDealStageInput;
use Foresi\Deals\Application\Api\CreateDealInput;
use Foresi\Deals\Application\Api\CreateDealStageInput;
use Foresi\Deals\Application\Api\CreateFollowUpDealInput;
use Foresi\Deals\Application\Api\CreateLostReasonInput;
use Foresi\Deals\Application\Api\LoseDealInput;
use Foresi\Deals\Application\Api\RemoveDealLineInput;
use Foresi\Deals\Application\Api\RemoveDealLinkInput;
use Foresi\Deals\Application\Api\ReopenDealInput;
use Foresi\Deals\Application\Api\ReorderDealLinesInput;
use Foresi\Deals\Application\Api\ReorderDealStagesInput;
use Foresi\Deals\Application\Api\RestoreDealInput;
use Foresi\Deals\Application\Api\TrashDealInput;
use Foresi\Deals\Application\Api\UpdateDealInput;
use Foresi\Deals\Application\Api\UpdateDealLineInput;
use Foresi\Deals\Application\Api\UpdateDealStageInput;
use Foresi\Deals\Application\Api\UpdateLostReasonInput;
use Foresi\Deals\Application\Api\WinDealInput;
use Foresi\Deals\Application\SalesAccess;
use Foresi\Deals\Application\SalesRefused;
use Foresi\Tests\Deals\Support\SalesTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Deals are for the owners and members only (ADR 0041): a guest, and no one
 * signed in, is refused by every use case before anything else. The records
 * they name exist, so only the access check refuses them.
 */
final class SalesGuestTest extends SalesTestCase
{
    public function testAGuestAndNobodyAreRefusedByEveryUseCase(): void
    {
        $deal = $this->deal();
        $other = $this->deal('Gutter audit');
        $line = $this->typedLine($deal);
        $link = $this->links()->add(new AddDealLinkInput($other->id(), $deal->id()));
        $stage = $this->stages['meeting']->id();
        $reason = $this->reasons['price']->id();
        $calls = [
            fn (): mixed => $this->deals()->create(new CreateDealInput('Audit', $this->client->id())),
            fn (): mixed => $this->deals()->update(new UpdateDealInput($deal->id(), title: 'Renamed')),
            fn (): mixed => $this->deals()->changeStage(new ChangeDealStageInput($deal->id(), $stage)),
            fn (): mixed => $this->deals()->win(new WinDealInput($deal->id())),
            fn (): mixed => $this->deals()->lose(new LoseDealInput($deal->id())),
            fn (): mixed => $this->deals()->reopen(new ReopenDealInput($deal->id())),
            fn (): mixed => $this->deals()->trash(new TrashDealInput($deal->id())),
            fn (): mixed => $this->deals()->restore(new RestoreDealInput($deal->id())),
            fn (): mixed => $this->deals()->createFollowUp(new CreateFollowUpDealInput($deal->id(), 'Follow-up')),
            fn (): mixed => $this->links()->add(new AddDealLinkInput($deal->id(), $other->id())),
            fn (): mixed => $this->links()->remove(new RemoveDealLinkInput($deal->id(), $link->id())),
            fn (): mixed => $this->lines()->add(new AddDealLineInput($deal->id(), name: 'Survey')),
            fn (): mixed => $this->lines()->update(new UpdateDealLineInput($deal->id(), $line->id(), name: 'Renamed')),
            fn (): mixed => $this->lines()->remove(new RemoveDealLineInput($deal->id(), $line->id())),
            fn (): mixed => $this->lines()->reorder(new ReorderDealLinesInput($deal->id(), [$line->id()])),
            fn (): mixed => $this->stageWrites()->create(new CreateDealStageInput('Site visit')),
            fn (): mixed => $this->stageWrites()->update(new UpdateDealStageInput($stage, 'Renamed')),
            fn (): mixed => $this->stageWrites()->reorder(new ReorderDealStagesInput(array_map(static fn (\Foresi\Deals\Domain\Stage $stage): string => $stage->id(), array_values($this->stages)))),
            fn (): mixed => $this->stageWrites()->archive(new ArchiveDealStageInput($stage)),
            fn (): mixed => $this->reasonWrites()->create(new CreateLostReasonInput('Budget cut')),
            fn (): mixed => $this->reasonWrites()->update(new UpdateLostReasonInput($reason, 'Renamed')),
            fn (): mixed => $this->reasonWrites()->archive(new ArchiveLostReasonInput($reason)),
            fn (): mixed => $this->reads()->deals(),
            fn (): mixed => $this->reads()->dealsOfClient($this->client->id()),
            fn (): mixed => $this->reads()->deal($deal->id()),
            fn (): mixed => $this->reads()->stages(),
            fn (): mixed => $this->reads()->lostReasons(),
        ];
        $events = $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM event_log');

        foreach ([fn () => $this->signIn($this->guest), fn () => self::getContainer()->get(TokenStorageInterface::class)->setToken(null)] as $who) {
            $who();
            foreach ($calls as $index => $call) {
                try {
                    $call();
                    self::fail(\sprintf('Use case %d is not refused.', $index));
                } catch (SalesRefused $refused) {
                    self::assertSame('forbidden', $refused->reason(), (string) $index);
                    self::assertSame(403, $refused->kind()->status());
                    self::assertNull($refused->field());
                    self::assertSame([], $refused->details());
                    self::assertSame('Only the owners and members of the organisation work with its deals.', $refused->getMessage());
                }
            }
            self::assertFalse(self::getContainer()->get(SalesAccess::class)->mayWork());
        }
        self::assertSame($events, $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM event_log'), 'Nothing was recorded.');

        $this->signIn($this->member);
        self::assertTrue(self::getContainer()->get(SalesAccess::class)->mayWork());
        self::assertCount(2, $this->reads()->deals(), 'A member works with deals.');
    }
}
