<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Contract;

/**
 * A source that also knows amounts without a day (UndatedFlow). The Cash flow
 * page shows them in a "No date" column after the last period, and the API
 * under "undated"; they are left out of every balance, the lowest point, the
 * minimum-balance warning and what other modules read of the forecast
 * (ForecastReader), such as the taxes forecast. The same filters apply to
 * them as to the dated flows. Since module API 1.8.
 */
interface UndatedFlowSource extends CashFlowSource
{
    /**
     * The open amounts of the firm that have no day yet.
     *
     * @param \DateTimeImmutable $today midnight of today in the instance's zone (InstanceClock)
     *
     * @return iterable<UndatedFlow>
     */
    public function undatedFlows(\DateTimeImmutable $today): iterable;
}
