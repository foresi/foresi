<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Sales\Infrastructure;

use Foresi\Sales\Contract\Field\DealExtension;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Application\View\WorkingFilter;
use Foresi\Tests\Sales\Support\SalesScreenTestCase;
use Foresi\Tests\Shared\Fixtures\Field\LentFields;

/**
 * The custom fields of a deal (ADR 0045) in the list of deals: a column, a filter and
 * the search, with the test-only fields lent to the deals.
 */
final class DealCustomFieldsTest extends SalesScreenTestCase
{
    public function testTheListShowsFiltersAndSearchesTheFields(): void
    {
        $fields = LentFields::lend(self::getContainer(), DealExtension::RECORD);
        $audit = $this->newDeal('Roof audit');
        $inspection = $this->newDeal('Wall inspection');
        $this->newDeal('Window repair');
        $fields->write(DealExtension::RECORD, $audit->id(), ['test_fields_code' => 'RA-17', 'test_fields_size' => 'large']);
        $fields->write(DealExtension::RECORD, $inspection->id(), ['test_fields_size' => 'small']);
        $this->em()->flush();

        $large = $this->client->request('GET', '/sales/deals?cols=title,test_fields_code&f='.WorkingFilter::encode(ViewFilter::all(new Condition('test_fields_size', 'is', 'large'))))->filter('table')->text();
        $found = $this->client->request('GET', '/sales/deals?q=ra-17')->filter('table')->text();

        self::assertStringContainsString('RA-17', $large);
        self::assertStringContainsString('Roof audit', $large);
        self::assertStringNotContainsString('Wall inspection', $large);
        self::assertStringContainsString('Roof audit', $found);
        self::assertStringNotContainsString('Window repair', $found);
    }
}
