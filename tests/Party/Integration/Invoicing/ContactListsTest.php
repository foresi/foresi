<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Party\Integration\Invoicing;

use Foresi\Party\Application\View\ContactLists;
use Foresi\Shared\Application\View\FieldType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The Invoicing module relates its invoices to a contact (DS6): the address book's
 * list gets the relation and the number of a related invoice as its fields.
 */
final class ContactListsTest extends KernelTestCase
{
    public function testTheInvoicesOfAContactAreAFieldWithTheNameOfARelatedInvoice(): void
    {
        $fields = self::getContainer()->get(ContactLists::class)->list()->fields;

        $keys = array_keys($fields);
        self::assertSame(['invoices', 'invoices.name'], \array_slice($keys, (int) array_search('invoices', $keys, true), 2));
        self::assertGreaterThan(array_search('contact_persons.name', $keys, true), array_search('invoices', $keys, true), 'Other modules\' relations follow the address book\'s own.');
        self::assertSame(FieldType::Relation, $fields['invoices']->type);
        self::assertSame(['is_not_empty', 'is_empty'], $fields['invoices']->operators);
        self::assertSame('invoice.relation.invoices', $fields['invoices.name']->group);
        self::assertSame('ci.number', $fields['invoices.name']->expression);
    }
}
