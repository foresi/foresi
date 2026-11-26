<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Application\View;

use Foresi\Inbox\Domain\ItemState;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\DefaultView;
use Foresi\Shared\Application\View\Field;
use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Shared\Application\View\ListDefinitionSource;
use Foresi\Shared\Application\View\ListFields;
use Foresi\Shared\Application\View\ViewFilter;

/**
 * The person's inbox and the views it ships (docs/design/list-pages.md): its All,
 * named New, holds the items that wait; Done and Discarded hold those; Unmatched
 * holds the new items no contact is matched to. A view that names no state means
 * New, as the notifications inbox's All leaves out what is done. No tab counts.
 * Its quick filters are State, Contact and Suggestions ("With suggestions": items
 * an agent's suggestions wait on, the open ones only).
 */
final class InboxLists implements ListDefinitionSource
{
    public const string KEY = 'inbox.items';
    public const string STATE = 'state';
    public const string MATCH = 'match';
    /** The one value of the match field. */
    public const string UNMATCHED = 'unmatched';
    public const string SUGGESTIONS = 'suggestions';
    /** The values of the suggestions field: with or without open suggestions. */
    public const string WITH_SUGGESTIONS = 'with';
    public const string WITHOUT_SUGGESTIONS = 'without';
    public const string DONE_VIEW = 'done';
    public const string DISCARDED_VIEW = 'discarded';
    public const string UNMATCHED_VIEW = 'unmatched';

    /** @var array<string, ListDefinition> by the audience of the fields (ListFields::audience()) */
    private array $list = [];

    public function __construct(private readonly ListFields $fields)
    {
    }

    public function lists(): iterable
    {
        yield $this->list();
    }

    public function list(): ListDefinition
    {
        return $this->list[$this->fields->audience()] ??= new ListDefinition(self::KEY, $this->fields->of(self::KEY, $this->ownFields()), $this->defaults(), [self::STATE, self::MATCH, self::SUGGESTIONS]);
    }

    /**
     * @return list<Field>
     */
    private function ownFields(): array
    {
        $states = array_map(static fn (ItemState $state): string => $state->value, ItemState::cases());

        return [
            new Field(self::STATE, FieldType::Enum, 'inbox.field.state', ['is'], $states, array_combine($states, array_map(static fn (string $state): string => 'inbox.state.'.$state, $states))),
            new Field(self::MATCH, FieldType::Enum, 'inbox.field.match', ['is'], [self::UNMATCHED], [self::UNMATCHED => 'inbox.match.unmatched']),
            new Field(self::SUGGESTIONS, FieldType::Enum, 'inbox.field.suggestions', ['is'], [self::WITH_SUGGESTIONS, self::WITHOUT_SUGGESTIONS], [self::WITH_SUGGESTIONS => 'inbox.suggestions_filter.with', self::WITHOUT_SUGGESTIONS => 'inbox.suggestions_filter.without']),
        ];
    }

    /**
     * @return list<DefaultView>
     */
    private function defaults(): array
    {
        return [
            new DefaultView(ListDefinition::ALL, 'inbox.tab.new', showCount: false, position: 0),
            new DefaultView(self::DONE_VIEW, 'inbox.tab.done', ViewFilter::all(new Condition(self::STATE, 'is', ItemState::Done->value)), showCount: false, position: 1),
            new DefaultView(self::DISCARDED_VIEW, 'inbox.tab.discarded', ViewFilter::all(new Condition(self::STATE, 'is', ItemState::Discarded->value)), showCount: false, position: 2),
            new DefaultView(self::UNMATCHED_VIEW, 'inbox.tab.unmatched', ViewFilter::all(new Condition(self::MATCH, 'is', self::UNMATCHED)), showCount: false, position: 3),
        ];
    }
}
