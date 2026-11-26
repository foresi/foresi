<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application\View;

use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Shared\Application\View\DefaultView;
use Foresi\Shared\Application\View\Field;
use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Shared\Application\View\ListDefinitionSource;
use Foresi\Shared\Application\View\ListFields;

/**
 * The number series of the firm on Settings → Firm → Invoicing as a list with views
 * (docs/design/list-pages.md, rule 8). Its one shipped view is All, as the page showed every
 * series; a person makes their own on the name, the kind of documents and the default.
 */
final class SeriesLists implements ListDefinitionSource
{
    public const string KEY = 'invoicing.series';
    public const string NAME = 'name';
    public const string KIND = 'kind';
    public const string DEFAULT = 'default';

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
        $kinds = array_map(static fn (DocumentKind $kind): string => $kind->value, DocumentKind::cases());

        return $this->list ??= new ListDefinition(self::KEY, $this->fields->of(self::KEY, [
            new Field(self::NAME, FieldType::Text, 'series.field.name'),
            new Field(self::KIND, FieldType::Enum, 'series.field.kind', options: $kinds, optionLabels: array_combine($kinds, array_map(static fn (string $kind): string => 'series.kind.'.$kind, $kinds))),
            new Field(self::DEFAULT, FieldType::Boolean, 'series.column.default'),
        ]), [new DefaultView(ListDefinition::ALL, 'series.tab.all', showCount: false, position: 0)]);
    }
}
