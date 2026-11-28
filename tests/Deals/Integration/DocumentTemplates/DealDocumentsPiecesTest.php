<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Deals\Integration\DocumentTemplates;

use Foresi\Deals\Application\Api\TrashDealInput;
use Foresi\Deals\Application\Api\WinDealInput;
use Foresi\Deals\Contract\SalesSlots;
use Foresi\Deals\Integration\DocumentTemplates\DealDocumentsSection;
use Foresi\Deals\Integration\DocumentTemplates\DealLinkTarget;
use Foresi\Deals\Integration\DocumentTemplates\DealVariables;
use Foresi\Deals\Integration\DocumentTemplates\NewDocumentDealAction;
use Foresi\DocumentTemplates\Application\DocumentVariables;
use Foresi\DocumentTemplates\Application\LinkTargets;
use Foresi\DocumentTemplates\Contract\RecordLink;
use Foresi\DocumentTemplates\Contract\VariableDefinition;
use Foresi\Tests\Deals\Support\SalesTestCase;
use Foresi\Ui\Contract\Slot\SlotItem;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * What Sales gives Documents: a deal as a link of a document, {{ deal.* }},
 * the Documents section of a deal and "New document" in its header. A deal in
 * the trash, unknown or hidden from a guest is gone for all of them.
 */
final class DealDocumentsPiecesTest extends SalesTestCase
{
    private const string UNKNOWN = '0192f1a4-0000-7000-8000-000000000000';

    protected function setUp(): void
    {
        parent::setUp();
        self::getContainer()->get(LocaleSwitcher::class)->setLocale('en');
    }

    public function testADealIsALinkWithItsTitleAndPage(): void
    {
        $deal = $this->deal('Roof audit');
        $target = self::getContainer()->get(DealLinkTarget::class);

        self::assertSame(['deal'], $target->kinds());
        self::assertTrue($target->exists('deal', $deal->id()));
        self::assertSame('Roof audit', $target->label('deal', $deal->id()));
        self::assertSame('/deals/'.$deal->id(), $target->url('deal', $deal->id()));
        self::assertContains('deal', self::getContainer()->get(LinkTargets::class)->kinds());
    }

    public function testATrashedUnknownOrOtherRecordIsNoDeal(): void
    {
        $deal = $this->deal('Roof audit');
        $trashed = $this->deal('Old audit');
        $this->deals()->trash(new TrashDealInput($trashed->id()));
        $target = self::getContainer()->get(DealLinkTarget::class);

        foreach ([['deal', $trashed->id()], ['deal', self::UNKNOWN], ['deal', 'not-an-id'], ['contact', $deal->id()]] as [$kind, $id]) {
            self::assertFalse($target->exists($kind, $id), $kind.':'.$id);
            self::assertNull($target->label($kind, $id), $kind.':'.$id);
            self::assertNull($target->url($kind, $id), $kind.':'.$id);
        }
    }

    public function testAGuestSeesNoDeal(): void
    {
        $deal = $this->deal('Roof audit');
        $this->signIn($this->guest);

        self::assertFalse(self::getContainer()->get(DealLinkTarget::class)->exists('deal', $deal->id()));
        self::assertNull(self::getContainer()->get(DealVariables::class)->values([new RecordLink('deal', $deal->id())]));
    }

    public function testTheVariablesAreDefinedWithExamplesAndLabels(): void
    {
        $source = self::getContainer()->get(DealVariables::class);

        self::assertSame('deal', $source->prefix());
        $definitions = $source->definitions();
        self::assertSame(['title', 'owner', 'stage', 'status', 'lines', 'one_off_totals', 'monthly_totals', 'hourly_lines'], array_map(static fn (VariableDefinition $definition): string => $definition->name, $definitions));
        self::assertSame('Title of the deal', $definitions[0]->label);
        foreach ([4 => ['name', 'unit', 'quantity', 'unit_price', 'billing_period'], 5 => ['currency', 'amount'], 6 => ['currency', 'amount'], 7 => ['name', 'rate', 'expected_hours', 'expected_amount']] as $i => $columns) {
            $example = $definitions[$i]->example;
            self::assertIsArray($example);
            self::assertSame($columns, array_keys($example[0]));
        }
        self::assertArrayHasKey('deal', self::getContainer()->get(DocumentVariables::class)->definitions());
    }

    public function testTheVariablesNeedADealLinkToADealNotInTheTrash(): void
    {
        $source = self::getContainer()->get(DealVariables::class);
        $trashed = $this->deal('Old audit');
        $this->deals()->trash(new TrashDealInput($trashed->id()));

        self::assertNull($source->values([]));
        self::assertNull($source->values([new RecordLink('contact', $this->client->id())]));
        self::assertNull($source->values([new RecordLink('deal', self::UNKNOWN)]));
        self::assertNull($source->values([new RecordLink('deal', 'not-an-id')]));
        self::assertNull($source->values([new RecordLink('deal', $trashed->id())]));
    }

    public function testTheFirstDealLinkFillsTheVariables(): void
    {
        $first = $this->deal('Roof audit');
        $second = $this->deal('Roof repair');
        $this->deals()->win(new WinDealInput($first->id()));

        $values = self::getContainer()->get(DealVariables::class)->values([new RecordLink('contact', $this->client->id()), new RecordLink('deal', $first->id()), new RecordLink('deal', $second->id())]);

        self::assertSame(['title' => 'Roof audit', 'owner' => 'Olga Owner', 'stage' => 'Inquiry', 'status' => 'Won', 'lines' => [], 'one_off_totals' => [], 'monthly_totals' => [], 'hourly_lines' => []], $values);
    }

    public function testTheMonthlyTotalsWithoutApproximationHaveNoSign(): void
    {
        $deal = $this->deal('Roof audit');
        $this->typedLine($deal, 'Audit', '1000.50', '2');

        $values = self::getContainer()->get(DealVariables::class)->values([new RecordLink('deal', $deal->id())]);

        self::assertSame([['currency' => 'CZK', 'amount' => "2,001.00\u{a0}CZK"]], $values['one_off_totals'] ?? null);
        self::assertSame([], $values['monthly_totals'] ?? null);
    }

    public function testTheSectionAndTheActionFillOnlyTheirSlotOfADeal(): void
    {
        $context = ['dealId' => self::UNKNOWN, 'clientId' => $this->client->id(), 'contactPersonIds' => [], 'return' => '/deals/'.self::UNKNOWN];
        $section = self::getContainer()->get(DealDocumentsSection::class);
        $action = self::getContainer()->get(NewDocumentDealAction::class);

        $sections = $this->items($section->items(SalesSlots::DEAL_SECTIONS, $context));
        self::assertCount(1, $sections);
        self::assertSame(['key' => 'documents', 'label' => 'Documents', 'href' => '/documents/for/deal/'.self::UNKNOWN], $sections[0]->data);
        self::assertSame(20, $sections[0]->weight);
        self::assertNull($sections[0]->template);

        $actions = $this->items($action->items(SalesSlots::DEAL_ACTIONS, $context));
        self::assertCount(1, $actions);
        self::assertSame(['key' => 'new-document', 'label' => 'New document', 'icon' => 'file-text', 'dialog' => true, 'href' => '/documents/new?link_kind=deal&link_id='.self::UNKNOWN.'&links%5B0%5D=contact:'.$this->client->id()], $actions[0]->data);
        self::assertSame(20, $actions[0]->weight);

        self::assertSame([], $this->items($section->items(SalesSlots::DEAL_ACTIONS, $context)));
        self::assertSame([], $this->items($action->items(SalesSlots::DEAL_SECTIONS, $context)));
        self::assertSame([], $this->items($section->items(SalesSlots::DEAL_SECTIONS, ['dealId' => 7] + $context)));
        self::assertSame([], $this->items($action->items(SalesSlots::DEAL_ACTIONS, ['dealId' => null] + $context)));
        self::assertSame([], $this->items($action->items(SalesSlots::DEAL_ACTIONS, ['clientId' => null] + $context)));
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
