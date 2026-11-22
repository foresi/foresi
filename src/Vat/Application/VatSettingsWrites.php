<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Application;

use Foresi\Party\Application\TransactionalWork;
use Foresi\Vat\Domain\VatSettings;

/**
 * Sets where the firm pays its VAT. The variable symbol left empty is the
 * one the firm's country suggests.
 */
final readonly class VatSettingsWrites
{
    public function __construct(
        private VatAccess $access,
        private VatStore $store,
        private VatSettingsReads $reads,
        private TransactionalWork $transaction,
    ) {
    }

    /**
     * @throws VatRefused forbidden, not_found, or invalid with office_account_invalid or variable_symbol_invalid
     */
    public function set(SetVatSettingsInput $input): VatSettingsView
    {
        $this->access->denyUnlessStaff();
        $account = trim($input->officeAccount);
        if ('' === $account || mb_strlen($account) > VatSettings::MAX_ACCOUNT) {
            throw VatRefused::invalid('office_account_invalid', 'officeAccount', \sprintf('The account of the tax office has 1 to %d characters.', VatSettings::MAX_ACCOUNT));
        }
        $symbol = trim($input->variableSymbol);
        if (mb_strlen($symbol) > VatSettings::MAX_VARIABLE_SYMBOL) {
            throw VatRefused::invalid('variable_symbol_invalid', 'variableSymbol', \sprintf('A variable symbol has at most %d characters.', VatSettings::MAX_VARIABLE_SYMBOL));
        }
        $suggestion = $this->reads->suggestion();
        if ('' === $symbol) {
            $symbol = $suggestion->variableSymbol ?? '';
        }

        return $this->transaction->run(function () use ($account, $symbol, $suggestion): VatSettingsView {
            $settings = $this->store->settings();
            if ($settings instanceof VatSettings) {
                $settings->change($account, $symbol);
            } else {
                $settings = new VatSettings($account, $symbol);
                $this->store->add($settings);
            }

            return VatSettingsReads::view($settings, $suggestion);
        });
    }
}
