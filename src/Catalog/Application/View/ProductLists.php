<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Catalog\Application\View;

use Foresi\Catalog\Contract\BillingKind;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\DefaultView;
use Foresi\Shared\Application\View\Field;
use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Shared\Application\View\ListDefinitionSource;
use Foresi\Shared\Application\View\ListFields;
use Foresi\Shared\Application\View\ViewFilter;

/**
 * The products and the views they ship: All (the products not archived),
 * One-off, Recurring and Archived, as the address book ships its views. The
 * name is a filterable attribute of the list's data source; the archive and
 * the billing kind are the module's own fields.
 *
 * The list leaves out the archived products unless a condition asks for them:
 * All holds no condition, as every list's All, and still shows no archived
 * product.
 */
final class ProductLists implements ListDefinitionSource
{
    public const string KEY = 'catalog.products';
    public const string ARCHIVED = 'archived';
    public const string BILLING_KIND = 'billing_kind';

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
        $kinds = array_column(BillingKind::cases(), 'value');

        return $this->list ??= new ListDefinition(self::KEY, $this->fields->of(self::KEY, [
            new Field(self::ARCHIVED, FieldType::Boolean, 'catalog.products.field.archived'),
            new Field(self::BILLING_KIND, FieldType::Enum, 'catalog.product.field.billing_kind', options: $kinds, optionLabels: array_combine($kinds, array_map(static fn (string $kind): string => 'catalog.billing_kind.'.$kind, $kinds))),
        ]), $this->defaults());
    }

    /**
     * @return list<DefaultView>
     */
    private function defaults(): array
    {
        return [
            new DefaultView(ListDefinition::ALL, 'catalog.products.tab.all', showCount: false, position: 0),
            new DefaultView('one_off', 'catalog.products.tab.one_off', ViewFilter::all(new Condition(self::BILLING_KIND, 'is', BillingKind::OneOff->value)), showCount: false, position: 1),
            new DefaultView('recurring', 'catalog.products.tab.recurring', ViewFilter::all(new Condition(self::BILLING_KIND, 'is', BillingKind::Recurring->value)), showCount: false, position: 2),
            new DefaultView(self::ARCHIVED, 'catalog.products.tab.archived', ViewFilter::all(new Condition(self::ARCHIVED, 'is', true)), showCount: false, position: 3),
        ];
    }
}
