<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Application;

use Foresi\Party\Domain\LegalEntity;
use Foresi\Vat\Contract\VatCountryRules;

/**
 * The VAT rules of the firm's country, from the installed country modules.
 */
final readonly class VatRules
{
    /**
     * @param iterable<VatCountryRules> $rules
     */
    public function __construct(private iterable $rules)
    {
    }

    /**
     * The rules of the country of the firm's address, or null when no
     * installed module has them.
     */
    public function of(LegalEntity $firm): ?VatCountryRules
    {
        foreach ($this->rules as $rules) {
            if ($firm->address()->countryCode === $rules->country()) {
                return $rules;
            }
        }

        return null;
    }
}
