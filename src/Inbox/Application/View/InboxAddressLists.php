<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Application\View;

use Foresi\Shared\Application\View\DefaultView;
use Foresi\Shared\Application\View\Field;
use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Shared\Application\View\ListDefinitionSource;
use Foresi\Shared\Application\View\ListFields;

/**
 * The person's inbox addresses on Account → Inbox as a list with views
 * (docs/design/list-pages.md, rule 8). Its one shipped view is All, the active address the
 * page always showed.
 */
final class InboxAddressLists implements ListDefinitionSource
{
    public const string KEY = 'inbox.addresses';
    public const string LABEL = 'label';
    public const string DROPPED = 'dropped';

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
            new Field(self::LABEL, FieldType::Text, 'inbox.account.field.label'),
            new Field(self::DROPPED, FieldType::Number, 'inbox.account.column.dropped'),
        ]), [new DefaultView(ListDefinition::ALL, 'inbox.account.tab.all', showCount: false, position: 0)]);
    }
}
