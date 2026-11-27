<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Application\View;

use Foresi\Shared\Application\View\DefaultView;
use Foresi\Shared\Application\View\Field;
use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Shared\Application\View\ListDefinitionSource;
use Foresi\Shared\Application\View\ListFields;

/**
 * The VAT statuses of the firm over time, on the VAT tab of the firm, as a list with views
 * (docs/design/list-pages.md, rule 8). Its one shipped view is All, every status the firm had.
 */
final class FirmVatLists implements ListDefinitionSource
{
    public const string KEY = 'party.firm_vat';
    public const string STATUS = 'status';
    public const string CURRENT = 'current';

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
            new Field(self::STATUS, FieldType::Text, 'firm.vat.column.status'),
            new Field(self::CURRENT, FieldType::Boolean, 'firm.vat.field.current'),
        ]), [new DefaultView(ListDefinition::ALL, 'firm.vat.tab.all', showCount: false, position: 0)]);
    }
}
