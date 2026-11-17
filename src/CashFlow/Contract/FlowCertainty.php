<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\CashFlow\Contract;

/**
 * How sure an expected flow is: a document that exists, an occurrence of a
 * schedule such as a recurring invoice, a plan someone entered, or an estimate
 * a source computed.
 */
enum FlowCertainty: string
{
    case Document = 'document';
    case Schedule = 'schedule';
    case Plan = 'plan';
    case Estimate = 'estimate';
}
