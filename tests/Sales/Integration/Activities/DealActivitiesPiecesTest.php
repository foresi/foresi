<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Sales\Integration\Activities;

use Foresi\Activities\Application\ActivityTargets;
use Foresi\Sales\Application\Api\TrashDealInput;
use Foresi\Sales\Contract\SalesSlots;
use Foresi\Sales\Integration\Activities\DealActivitiesSection;
use Foresi\Sales\Integration\Activities\DealActivityActions;
use Foresi\Sales\Integration\Activities\DealActivityTarget;
use Foresi\Tests\Sales\Support\SalesTestCase;
use Foresi\Ui\Contract\Slot\SlotItem;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * What Sales gives Activities: a deal as a record an activity is about, seen
 * and linked by the owners and members while it is out of the trash; the
 * timeline section of a deal; and "Log activity" and "Plan activity" in its
 * header with the deal, the client and the contact persons linked.
 */
final class DealActivitiesPiecesTest extends SalesTestCase
{
    private const string UNKNOWN = '0192f1a4-0000-7000-8000-000000000000';

    public function testADealIsSeenAndLinkedWithItsTitleAndPage(): void
    {
        $deal = $this->deal('Roof audit');
        $target = self::getContainer()->get(DealActivityTarget::class);

        self::assertSame(['deal'], $target->kinds());
        self::assertTrue($target->canSee('deal', $deal->id()));
        self::assertTrue($target->canLink('deal', $deal->id()));
        self::assertSame('Roof audit', $target->label('deal', $deal->id()));
        self::assertSame('/sales/deals/'.$deal->id(), $target->url('deal', $deal->id()));
        self::assertContains('deal', self::getContainer()->get(ActivityTargets::class)->kinds());

        // A member sees the deals too.
        $this->signIn($this->member);
        self::assertTrue($target->canSee('deal', $deal->id()));
    }

    public function testATrashedUnknownOrOtherRecordIsNoDeal(): void
    {
        $deal = $this->deal('Roof audit');
        $trashed = $this->deal('Old audit');
        $this->deals()->trash(new TrashDealInput($trashed->id()));
        $target = self::getContainer()->get(DealActivityTarget::class);

        foreach ([['deal', $trashed->id()], ['deal', self::UNKNOWN], ['deal', 'not-an-id'], ['contact', $deal->id()]] as [$kind, $id]) {
            self::assertFalse($target->canSee($kind, $id), $kind.':'.$id);
            self::assertFalse($target->canLink($kind, $id), $kind.':'.$id);
            self::assertNull($target->label($kind, $id), $kind.':'.$id);
            self::assertNull($target->url($kind, $id), $kind.':'.$id);
        }
    }

    public function testAGuestSeesNoDeal(): void
    {
        $deal = $this->deal('Roof audit');
        $this->signIn($this->guest);

        $target = self::getContainer()->get(DealActivityTarget::class);
        self::assertFalse($target->canSee('deal', $deal->id()));
        self::assertFalse($target->canLink('deal', $deal->id()));
    }

    public function testTheTimelineSectionComesBackToTheDeal(): void
    {
        self::getContainer()->get(LocaleSwitcher::class)->setLocale('en');
        $section = self::getContainer()->get(DealActivitiesSection::class);
        $context = ['dealId' => self::UNKNOWN, 'clientId' => $this->client->id(), 'contactPersonIds' => [], 'return' => '/sales/deals/'.self::UNKNOWN];

        $items = $this->items($section->items(SalesSlots::DEAL_SECTIONS, $context));
        self::assertCount(1, $items);
        self::assertSame(['key' => 'activities', 'label' => 'Activities', 'href' => '/activities/for/deal/'.self::UNKNOWN.'?return=/sales/deals/'.self::UNKNOWN], $items[0]->data);
        self::assertSame(10, $items[0]->weight);

        // Without a page to come back to, the timeline comes back to itself.
        unset($context['return']);
        self::assertSame('/activities/for/deal/'.self::UNKNOWN, $this->items($section->items(SalesSlots::DEAL_SECTIONS, $context))[0]->data['href'] ?? null);
        self::assertSame([], $this->items($section->items(SalesSlots::DEAL_ACTIONS, $context)));
        self::assertSame([], $this->items($section->items(SalesSlots::DEAL_SECTIONS, ['dealId' => 7] + $context)));
    }

    public function testLogAndPlanLinkTheDealTheClientAndTheContactPersons(): void
    {
        self::getContainer()->get(LocaleSwitcher::class)->setLocale('en');
        $actions = self::getContainer()->get(DealActivityActions::class);
        $context = ['dealId' => self::UNKNOWN, 'clientId' => $this->client->id(), 'contactPersonIds' => [$this->petra->id(), 3, $this->karel->id()], 'return' => '/sales/deals/'.self::UNKNOWN];
        $links = '&links%5B0%5D=contact:'.$this->client->id().'&links%5B1%5D=contact:'.$this->petra->id().'&links%5B2%5D=contact:'.$this->karel->id();

        $items = $this->items($actions->items(SalesSlots::DEAL_ACTIONS, $context));

        self::assertCount(2, $items);
        self::assertSame(['key' => 'log-activity', 'label' => 'Log activity', 'icon' => 'square-pen', 'dialog' => true, 'href' => '/activities/new?mode=log&link_kind=deal&link_id='.self::UNKNOWN.$links.'&return=/sales/deals/'.self::UNKNOWN, 'prominent' => true], $items[0]->data);
        self::assertSame(['key' => 'plan-activity', 'label' => 'Plan activity', 'icon' => 'calendar', 'dialog' => true, 'href' => '/activities/new?mode=plan&link_kind=deal&link_id='.self::UNKNOWN.$links.'&return=/sales/deals/'.self::UNKNOWN], $items[1]->data);
        self::assertSame([10, 11], [$items[0]->weight, $items[1]->weight]);

        // No contact persons, nor a page to come back to: the deal and the client alone.
        $items = $this->items($actions->items(SalesSlots::DEAL_ACTIONS, ['dealId' => self::UNKNOWN, 'clientId' => $this->client->id(), 'contactPersonIds' => 'none']));
        self::assertSame('/activities/new?mode=log&link_kind=deal&link_id='.self::UNKNOWN.'&links%5B0%5D=contact:'.$this->client->id(), $items[0]->data['href'] ?? null);

        self::assertSame([], $this->items($actions->items(SalesSlots::DEAL_SECTIONS, $context)));
        self::assertSame([], $this->items($actions->items(SalesSlots::DEAL_ACTIONS, ['dealId' => null] + $context)));
        self::assertSame([], $this->items($actions->items(SalesSlots::DEAL_ACTIONS, ['clientId' => 5] + $context)));
    }

    /**
     * @param iterable<SlotItem> $items
     *
     * @return list<SlotItem>
     */
    private function items(iterable $items): array
    {
        return array_values([...$items]);
    }
}
