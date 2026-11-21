<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * The rules of one country and one legal form, such as the Czech sole trader
 * (Goal 8). A country implements it in Country/Integration/TaxesAndContributions
 * (ADR 0033), so this module knows no law. The calculation is pure: it gets a
 * profile, the amounts and the days, and returns a result with its steps,
 * storing nothing.
 */
#[AutoconfigureTag(self::TAG)]
interface Regime
{
    public const string TAG = 'app.taxes_regime';

    /**
     * A stable key, such as "cz.sole_trader".
     */
    public function key(): string;

    /**
     * The ISO 3166-1 alpha-2 code of the country, such as "CZ".
     */
    public function country(): string;

    /**
     * The value of the legal_form status it computes, such as "sole_trader".
     */
    public function legalForm(): string;

    /**
     * @throws RulesNotAvailable for a year before the first year of its rules
     */
    public function compute(RegimeInput $input): RegimeResult;
}
