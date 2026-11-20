<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Contract;

/**
 * Who the owner pays: social insurance, health insurance, sickness insurance
 * and the income tax office.
 */
enum Authority: string
{
    case Social = 'social';
    case Health = 'health';
    case Sickness = 'sickness';
    case IncomeTax = 'income_tax';
}
