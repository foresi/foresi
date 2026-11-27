<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Application\View;

use Foresi\Shared\Application\View\DefaultView;
use Foresi\Shared\Application\View\Field;
use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Shared\Application\View\ListDefinitionSource;
use Foresi\Shared\Application\View\ListFields;
use Foresi\TaxesAndContributions\Contract\StatusKind;

/**
 * The statuses of the owner in the profile's Statuses dialog as a list with views
 * (docs/design/list-pages.md, rule 8). The dialog shows one table per kind of status; they
 * share this one list, its view, filter, search and columns. Its one shipped view is All.
 */
final class ProfileStatusLists implements ListDefinitionSource
{
    public const string KEY = 'taxes.profile_statuses';
    public const string VALUE = 'value';
    public const string KIND = 'kind';

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
        $kinds = array_map(static fn (StatusKind $kind): string => $kind->value, StatusKind::cases());

        return $this->list ??= new ListDefinition(self::KEY, $this->fields->of(self::KEY, [
            new Field(self::VALUE, FieldType::Text, 'taxes.profile.column.value'),
            new Field(self::KIND, FieldType::Enum, 'taxes.profile.field.kind', options: $kinds, optionLabels: array_combine($kinds, array_map(static fn (string $kind): string => 'taxes.status.kind.'.$kind, $kinds))),
        ]), [new DefaultView(ListDefinition::ALL, 'taxes.profile.tab.all', showCount: false, position: 0)]);
    }
}
