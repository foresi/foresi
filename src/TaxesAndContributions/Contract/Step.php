<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Contract;

/**
 * One step of the calculation, so any number can be traced (old T9): its
 * input, the rule it applies in a short English sentence, where the rule is
 * published, and its result. Amounts are decimal strings in CZK.
 */
final readonly class Step
{
    public const string PROFIT = 'profit';
    public const string SOCIAL = 'social';
    public const string HEALTH = 'health';
    public const string SICKNESS = 'sickness';
    public const string TAX_BASE = 'tax_base';
    public const string INCOME_TAX = 'income_tax';
    public const string CREDITS = 'credits';
    public const string CHILD_BENEFIT = 'child_benefit';
    public const string FLAT_RATE = 'flat_rate';

    /** The steps in the order of the pipeline; the flat rate only in a year with flat-rate months. */
    public const array KEYS = [self::PROFIT, self::SOCIAL, self::HEALTH, self::SICKNESS, self::TAX_BASE, self::INCOME_TAX, self::CREDITS, self::CHILD_BENEFIT, self::FLAT_RATE];

    /**
     * @param array<string, string> $input
     */
    public function __construct(
        public string $key,
        public array $input,
        public string $rule,
        public string $sourceUrl,
        public string $result,
    ) {
    }
}
