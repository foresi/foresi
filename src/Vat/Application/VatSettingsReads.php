<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Application;

use Foresi\Vat\Contract\PaymentSuggestion;
use Foresi\Vat\Contract\VatCountryRules;
use Foresi\Vat\Domain\VatSettings;

/**
 * The VAT payment details of the firm and the country's suggestion.
 */
final readonly class VatSettingsReads
{
    public function __construct(private VatAccess $access, private VatStore $store, private VatRules $rules)
    {
    }

    /**
     * @throws VatRefused forbidden or not_found
     */
    public function current(): VatSettingsView
    {
        $this->access->denyUnlessStaff();

        return self::view($this->store->settings(), $this->suggestion());
    }

    public static function view(?VatSettings $settings, ?PaymentSuggestion $suggestion): VatSettingsView
    {
        return new VatSettingsView($settings?->officeAccount(), $settings?->variableSymbol(), $suggestion);
    }

    /**
     * @throws VatRefused not_found
     */
    public function suggestion(): ?PaymentSuggestion
    {
        $firm = $this->access->firm();
        $rules = $this->rules->of($firm);

        return $rules instanceof VatCountryRules ? $rules->suggestedPayment($firm->vatId()?->toString()) : null;
    }
}
