<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Application\View;

use Foresi\Shared\Application\View\DefaultView;
use Foresi\Shared\Application\View\Field;
use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Shared\Application\View\ListDefinitionSource;
use Foresi\Shared\Application\View\ListFields;

/**
 * The reasons a deal is lost on Settings → Sales as a list with views (docs/design/list-pages.md,
 * rule 8), the second table of the page, under its list slot.
 * Its one shipped view is All, which shows the archived ones too, as the page always
 * did; a person makes their own views on the name and the archive.
 */
final class LostReasonLists implements ListDefinitionSource
{
    public const string KEY = 'sales.lost_reasons';
    public const string NAME = 'name';
    public const string ARCHIVED = 'archived';

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
            new Field(self::NAME, FieldType::Text, 'sales.settings.field.name'),
            new Field(self::ARCHIVED, FieldType::Boolean, 'sales.settings.archived'),
        ]), [new DefaultView(ListDefinition::ALL, 'sales.settings.tab.all', showCount: false, position: 0)]);
    }
}
