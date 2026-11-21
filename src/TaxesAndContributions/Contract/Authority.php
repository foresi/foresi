<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Contract;

/**
 * Who the owner pays: social insurance, health insurance, sickness insurance,
 * the income tax office, and the tax office's account for a flat-rate regime,
 * which takes the three levies it replaces in one payment.
 */
enum Authority: string
{
    case Social = 'social';
    case Health = 'health';
    case Sickness = 'sickness';
    case IncomeTax = 'income_tax';
    case FlatRate = 'flat_rate';
}
