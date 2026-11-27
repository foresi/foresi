<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Application\View;

use Foresi\Shared\Application\View\DefaultView;
use Foresi\Shared\Application\View\Field;
use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Shared\Application\View\ListDefinitionSource;
use Foresi\Shared\Application\View\ListFields;

/**
 * The bank accounts of the firm, on the bank tab of the firm, as a list with views
 * (docs/design/list-pages.md, rule 8). Its one shipped view is All, which shows the archived
 * accounts too, as the tab always did.
 */
final class FirmBankAccountLists implements ListDefinitionSource
{
    public const string KEY = 'banking.firm_accounts';
    public const string NAME = 'name';
    public const string IBAN = 'iban';
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
            new Field(self::NAME, FieldType::Text, 'bank.account.column.name'),
            new Field(self::IBAN, FieldType::Text, 'bank.account.column.iban'),
            new Field(self::ARCHIVED, FieldType::Boolean, 'bank.account.state.archived'),
        ]), [new DefaultView(ListDefinition::ALL, 'bank.account.tab.all', showCount: false, position: 0)]);
    }
}
