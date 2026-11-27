<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Calendar\Application\View;

use Foresi\Shared\Application\View\DefaultView;
use Foresi\Shared\Application\View\Field;
use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Shared\Application\View\ListDefinitionSource;
use Foresi\Shared\Application\View\ListFields;

/**
 * The person's calendar feeds on Account → Calendar as a list with views
 * (docs/design/list-pages.md, rule 8). Its one shipped view is All, which shows the revoked
 * feeds too, as the page always did.
 */
final class CalendarFeedLists implements ListDefinitionSource
{
    public const string KEY = 'calendar.feeds';
    public const string NAME = 'name';
    public const string REVOKED = 'revoked';

    private ?ListDefinition $list = null;

    public function __construct(private readonly ListFields $fields)
    {
    }

    public function lists(): iterable
    {
        yield $this->list();
    }

    public function list(): ListDefinition
    {
        return $this->list ??= new ListDefinition(self::KEY, $this->fields->of(self::KEY, [
            new Field(self::NAME, FieldType::Text, 'calendar.feeds.field.name'),
            new Field(self::REVOKED, FieldType::Boolean, 'calendar.feeds.state.revoked'),
        ]), [new DefaultView(ListDefinition::ALL, 'calendar.feeds.tab.all', showCount: false, position: 0)]);
    }
}
