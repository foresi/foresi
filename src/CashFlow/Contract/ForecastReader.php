<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Contract;

/**
 * The flows of the forecast as another module reads them (ADR 0033), such as
 * Taxes and contributions reading what the firm expects to earn by the end of
 * the year. It asks the sources the same way the forecast does, with the same
 * expected days and the same planned-item fulfilment, so no flow is counted
 * twice; it computes no balance.
 *
 * A source the query excludes is never called. A module that is itself a
 * cash-flow source excludes its own key, so reading the forecast never asks
 * it again.
 */
interface ForecastReader
{
    /**
     * @throws \DomainException a refusal of the cash flow (ADR 0032): who may not read it, a day out of range or no firm yet
     */
    public function read(ForecastQuery $query): ForecastRead;
}
