<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Integration\Country;

use Foresi\Invoicing\Application\RecurringFilter;
use Foresi\Invoicing\Domain\RecurringInvoice;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Tests\Invoicing\Application\RecurringTestCase;

/**
 * The text search of the recurring templates: the name, the description and the
 * customer's name, whatever the case, narrowing the list but not the counts of the tabs.
 */
final class RecurringSearchTest extends RecurringTestCase
{
    public function testTheListIsFoundByNameDescriptionOrCustomer(): void
    {
        $hosting = $this->save->create($this->recurringInput(overrides: ['description' => 'Managed hosting']));
        $this->save->create($this->recurringInput(overrides: ['description' => 'Support hours']));

        $ids = fn (string $text): array => array_map(static fn (RecurringInvoice $template): string => $template->id(), $this->templates->page(new RecurringFilter(null, false, 1, text: $text))->templates);
        self::assertCount(2, $ids(''));
        self::assertSame([$hosting->id()], $ids('MANAGED'));
        self::assertCount(2, $ids('example customer'), 'by the name of the customer');
        self::assertSame([], $ids('%'));
        self::assertSame([], $ids('_anaged'));
        self::assertSame([], $ids('nothing like it'));

        // The total follows the text, the counts of the tabs do not: a list without hits is no first run.
        $page = $this->templates->page(new RecurringFilter(null, false, 1, counted: ['all' => new ViewFilter()], text: 'nothing'));
        self::assertSame([0, 2], [$page->total, $page->counts['all']]);
    }
}
