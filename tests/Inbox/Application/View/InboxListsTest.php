<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Application\View;

use Foresi\Inbox\Application\View\InboxLists;
use Foresi\Shared\Application\View\DefaultView;
use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Application\View\ListFields;
use Foresi\Shared\Domain\View\Placement;
use PHPUnit\Framework\TestCase;

/**
 * The inbox's list definition (docs/design/list-pages.md): its fields, the tabs it ships
 * in their order, and the quick filters it pins.
 */
final class InboxListsTest extends TestCase
{
    public function testItShipsTheNewDoneDiscardedAndUnmatchedTabsInOrderWithoutCounts(): void
    {
        $list = $this->lists()->list();

        self::assertSame('inbox.items', $list->key);
        self::assertSame(
            [
                ['all', 'inbox.tab.new', [], 0, false],
                ['done', 'inbox.tab.done', [['field' => 'state', 'op' => 'is', 'value' => 'done']], 1, false],
                ['discarded', 'inbox.tab.discarded', [['field' => 'state', 'op' => 'is', 'value' => 'discarded']], 2, false],
                ['unmatched', 'inbox.tab.unmatched', [['field' => 'match', 'op' => 'is', 'value' => 'unmatched']], 3, false],
            ],
            array_map(static fn (DefaultView $view): array => [$view->slug, $view->name, $view->filter->toArray()['conditions'], $view->position, $view->showCount], $list->defaults),
        );
        foreach ($list->defaults as $view) {
            self::assertSame(Placement::Primary, $view->placement);
        }
    }

    public function testItsFieldsAreTheStateWhetherTheSenderIsMatchedAndOpenSuggestions(): void
    {
        $fields = $this->lists()->list()->fields;

        self::assertSame(['state', 'match', 'suggestions'], array_keys($fields));
        self::assertSame([FieldType::Enum, 'inbox.field.state', ['is']], [$fields['state']->type, $fields['state']->label, $fields['state']->operators]);
        self::assertSame(['new', 'done', 'discarded'], $fields['state']->options);
        self::assertSame(['new' => 'inbox.state.new', 'done' => 'inbox.state.done', 'discarded' => 'inbox.state.discarded'], $fields['state']->optionLabels);
        self::assertSame([FieldType::Enum, 'inbox.field.match', ['is']], [$fields['match']->type, $fields['match']->label, $fields['match']->operators]);
        self::assertSame(['unmatched'], $fields['match']->options);
        self::assertSame(['unmatched' => 'inbox.match.unmatched'], $fields['match']->optionLabels);
        self::assertSame([FieldType::Enum, 'inbox.field.suggestions', ['is']], [$fields['suggestions']->type, $fields['suggestions']->label, $fields['suggestions']->operators]);
        self::assertSame(['with' => 'inbox.suggestions_filter.with', 'without' => 'inbox.suggestions_filter.without'], $fields['suggestions']->optionLabels);
    }

    public function testEveryFieldIsAQuickFilterAndTheDefinitionIsBuiltOnce(): void
    {
        $lists = $this->lists();

        self::assertSame(['state', 'match', 'suggestions'], $lists->list()->pinned);
        self::assertSame($lists->list(), $lists->list());
        self::assertSame([$lists->list()], iterator_to_array($lists->lists(), false));
    }

    private function lists(): InboxLists
    {
        return new InboxLists(new ListFields([]));
    }
}
