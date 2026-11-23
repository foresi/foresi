<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Sales\Application;

use Foresi\Sales\Application\Api\AddDealLinkInput;
use Foresi\Sales\Application\Api\CreateFollowUpDealInput;
use Foresi\Sales\Application\Api\RemoveDealLinkInput;
use Foresi\Sales\Application\Api\TrashDealInput;
use Foresi\Sales\Application\DealLinkView;
use Foresi\Sales\Domain\Deal;
use Foresi\Sales\Domain\DealLine;
use Foresi\Sales\Domain\DealLink;
use Foresi\Tests\Sales\Support\SalesTestCase;

/**
 * Deals linked to deals: a follow-up for the same client that follows from
 * the deal before it, never a deal to itself and never a pair twice.
 */
final class DealLinksTest extends SalesTestCase
{
    public function testFollowUpSameClientOnly(): void
    {
        $audit = $this->deal('Roof audit', contactPersonIds: [$this->karel->id(), $this->petra->id()]);
        $inspection = $this->typedLine($audit, 'Inspection', '12000.00');
        $this->typedLine($audit, 'Travel', '800.00');
        $maintenance = $this->lines()->add(new \Foresi\Sales\Application\Api\AddDealLineInput($audit->id(), name: 'Maintenance', unitPrice: '1500.00', billingKind: 'recurring', recurrenceUnit: 'month', interval: 1));

        $followUp = $this->deals()->createFollowUp(new CreateFollowUpDealInput($audit->id(), 'Roof maintenance', [$maintenance->id(), $inspection->id()], $this->stages['proposal']->id(), $this->member->user()->id()));

        self::assertSame($this->client->id(), $followUp->clientPartyId());
        self::assertSame('Roof maintenance', $followUp->title());
        self::assertSame($this->stages['proposal']->id(), $followUp->stageId());
        self::assertSame($this->member->user()->id(), $followUp->ownerUserId());
        $detail = $this->reads()->deal($followUp->id());
        self::assertSame([$this->karel->id(), $this->petra->id()], $detail->contactPersonIds);
        // The lines named, copied as they are, in their order on the deal before.
        self::assertSame(['Inspection', 'Maintenance'], array_map(static fn (DealLine $line): string => $line->name(), $detail->lines));
        self::assertSame([0, 1], array_map(static fn (DealLine $line): int => $line->position(), $detail->lines));
        self::assertNotSame($inspection->id(), $detail->lines[0]->id());
        self::assertSame(['CZK' => 1_200_000], array_map(static fn (\Foresi\Shared\Domain\Money $money): int => $money->minor(), $detail->value->oneOff));
        self::assertSame(['CZK' => 150_000], array_map(static fn (\Foresi\Shared\Domain\Money $money): int => $money->minor(), $detail->value->monthly));
        self::assertCount(1, $detail->links);
        self::assertTrue($detail->links[0]->outgoing, 'The follow-up follows from the deal before.');
        self::assertSame($audit->id(), $detail->links[0]->other->id());
        $before = $this->reads()->deal($audit->id());
        self::assertSame([[$followUp->id(), false]], array_map(static fn (DealLinkView $view): array => [$view->other->id(), $view->outgoing], $before->links));
        self::assertCount(3, $before->lines, 'The deal before keeps its lines.');
        self::assertSame(['deal_id' => $followUp->id(), 'status' => 'open', 'stage_id' => $this->stages['proposal']->id()], $this->events('sales.deal.created')[1]);
        self::assertSame(['deal_id' => $audit->id(), 'status' => 'open', 'stage_id' => $this->stages['inquiry']->id()], array_reverse($this->events('sales.deal.updated'))[0]);

        // A deal of another client is never linked.
        $other = $this->deal('Gutter audit', $this->company('Beta Example a.s.'));
        $this->assertRefused('link_other_client', fn (): DealLink => $this->links()->add(new AddDealLinkInput($other->id(), $audit->id())));
        $this->assertRefused('line_unknown', fn (): Deal => $this->deals()->createFollowUp(new CreateFollowUpDealInput($audit->id(), 'Other', [$other->id()])));
        self::assertCount(3, $this->reads()->deals(), 'A refused follow-up is not kept.');
    }

    public function testFollowUpTakesOnlyTheContactPersonsStillLinked(): void
    {
        $audit = $this->deal('Roof audit', contactPersonIds: [$this->petra->id(), $this->karel->id()]);
        self::getContainer()->get(\Foresi\Party\Application\UnlinkContactPerson::class)->unlink($this->client, $this->petra);

        $followUp = $this->deals()->createFollowUp(new CreateFollowUpDealInput($audit->id(), 'Roof maintenance'));

        self::assertSame([$this->karel->id()], $this->reads()->deal($followUp->id())->contactPersonIds);
        self::assertSame([], $this->reads()->deal($followUp->id())->lines);
        self::assertSame($this->owner->user()->id(), $followUp->ownerUserId());
        self::assertSame($this->stages['inquiry']->id(), $followUp->stageId());
        // The deal before still names both; only the one still linked shows as a person.
        $before = $this->reads()->deal($audit->id());
        self::assertSame([$this->petra->id(), $this->karel->id()], $before->contactPersonIds);
        self::assertSame([$this->karel->id()], array_map(static fn (\Foresi\Party\Contract\PartyPersonSummary $person): string => $person->id, $before->contactPersons));
    }

    public function testNoSelfOrDuplicateLink(): void
    {
        $audit = $this->deal('Roof audit');
        $maintenance = $this->deal('Roof maintenance');

        $this->assertRefused('link_self', fn (): DealLink => $this->links()->add(new AddDealLinkInput($audit->id(), $audit->id())));
        $this->assertRefused('link_type_unknown', fn (): DealLink => $this->links()->add(new AddDealLinkInput($maintenance->id(), $audit->id(), 'replaces')));

        $link = $this->links()->add(new AddDealLinkInput($maintenance->id(), $audit->id()));
        self::assertSame($maintenance->id(), $link->fromDealId());
        self::assertSame($audit->id(), $link->toDealId());
        // Each deal of the pair changed.
        self::assertSame([$maintenance->id(), $audit->id()], array_column(\array_slice($this->events('sales.deal.updated'), -2), 'deal_id'));

        $refused = $this->assertRefused('link_duplicate', fn (): DealLink => $this->links()->add(new AddDealLinkInput($maintenance->id(), $audit->id())));
        self::assertSame(409, $refused->kind()->status());
        // Nor the other way round.
        $this->assertRefused('link_duplicate', fn (): DealLink => $this->links()->add(new AddDealLinkInput($audit->id(), $maintenance->id())));
        self::assertCount(1, $this->store()->linksOf($audit->id()));

        $this->links()->remove(new RemoveDealLinkInput($audit->id(), $link->id()));
        self::assertSame([], $this->store()->linksOf($audit->id()));
        self::assertSame([$audit->id(), $maintenance->id()], array_column(\array_slice($this->events('sales.deal.updated'), -2), 'deal_id'));
        $this->assertRefused('not_found', fn (): Deal => $this->links()->remove(new RemoveDealLinkInput($audit->id(), $link->id())));
        // Linked again once it was removed.
        $this->links()->add(new AddDealLinkInput($audit->id(), $maintenance->id()));

        $this->deals()->trash(new TrashDealInput($maintenance->id()));
        $other = $this->deal('Gutter audit');
        $this->assertRefused('not_found', fn (): DealLink => $this->links()->add(new AddDealLinkInput($other->id(), $maintenance->id())));
        // A link to a deal in the trash stays, and shows the deal as trashed.
        self::assertTrue($this->reads()->deal($audit->id())->links[0]->other->isTrashed());
    }
}
