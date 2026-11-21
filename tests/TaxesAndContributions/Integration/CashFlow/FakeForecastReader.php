<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\TaxesAndContributions\Integration\CashFlow;

use Foresi\CashFlow\Contract\ForecastQuery;
use Foresi\CashFlow\Contract\ForecastRead;
use Foresi\CashFlow\Contract\ForecastReader;
use Foresi\CashFlow\Contract\ReadFlow;

/**
 * A forecast that reads the flows the test gives it, with one source failed
 * and one restricted, and remembers what it was asked.
 */
final class FakeForecastReader implements ForecastReader
{
    /** @var list<ForecastQuery> */
    public array $queries = [];

    /**
     * @param list<ReadFlow> $flows
     */
    public function __construct(private readonly array $flows)
    {
    }

    public function read(ForecastQuery $query): ForecastRead
    {
        $this->queries[] = $query;

        return new ForecastRead($this->flows, ['purchasing.received_documents'], ['invoicing.invoices'], true);
    }
}
