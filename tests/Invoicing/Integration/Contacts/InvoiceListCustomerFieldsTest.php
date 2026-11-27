<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Integration\Contacts;

use Foresi\Invoicing\Application\View\InvoiceLists;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Party\Domain\Party;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The live customer contact (DS6) of the invoice lists: every field of the address book's data
 * source, one hop, so it is there only where the address book is installed.
 */
final class InvoiceListCustomerFieldsTest extends KernelTestCase
{
    public function testEveryFieldOfTheContactListIsOneHopFromTheInvoice(): void
    {
        $fields = self::getContainer()->get(InvoiceLists::class)->of(DocumentKind::Invoice)->fields;

        self::assertSame(
            [
                'customer.name', 'customer.kind', 'customer.roles', 'customer.registration_number', 'customer.vat_id', 'customer.email',
                'customer.phone', 'customer.mobile', 'customer.website', 'customer.registration_country', 'customer.billing_city',
                'customer.billing_country', 'customer.default_due_days', 'customer.currency', 'customer.payment_method', 'customer.language',
                'customer.reverse_charge', 'customer.reminders', 'customer.hourly_rate', 'customer.vies_result', 'customer.archived_at',
            ],
            array_values(array_filter(array_keys($fields), static fn (string $key): bool => str_starts_with($key, 'customer.'))),
        );
        self::assertSame('invoice.relation.customer', $fields['customer.phone']->group);
        self::assertSame('p.phone', $fields['customer.phone']->expression);
        self::assertSame(Party::class.' p WHERE p = i.customerParty', $fields['customer.phone']->within);
        self::assertSame(100, $fields['customer.hourly_rate']->scale);
    }
}
