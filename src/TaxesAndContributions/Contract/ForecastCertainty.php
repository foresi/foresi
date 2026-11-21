<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Contract;

/**
 * How sure a forecast amount is (Goal 1): a document that exists, such as an
 * open invoice or received document; an occurrence of a schedule, such as a
 * recurring invoice; or a plan someone entered. The module's own value, so
 * that its contract needs no other module's.
 */
enum ForecastCertainty: string
{
    case Document = 'document';
    case Schedule = 'schedule';
    case Plan = 'plan';
}
