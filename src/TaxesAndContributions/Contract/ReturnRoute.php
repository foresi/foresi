<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Contract;

/**
 * How the owner files the tax return, which decides its due day: on paper,
 * electronically or through a tax advisor.
 */
enum ReturnRoute: string
{
    case Paper = 'paper';
    case Electronic = 'electronic';
    case Advisor = 'advisor';
}
