<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application;

use App\Party\Domain\InvoicingDefaults;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Party\Domain\PartyFirmSettings;

/**
 * What a contact's invoices start with when a given firm issues them: the
 * firm's own settings for the contact when there are any, otherwise the
 * contact's defaults. Changing settings belongs in a unit of work.
 */
final readonly class ContactSettingsForFirm
{
    public function __construct(private FirmSettings $settings)
    {
    }

    public function defaultsFor(Party $party, LegalEntity $firm): InvoicingDefaults
    {
        return $this->settings->find($party, $firm)?->invoicingDefaults() ?? $party->invoicingDefaults();
    }

    /**
     * Settings equal to the contact's defaults are not worth a row, unless the
     * firm has one already: then it follows the change.
     *
     * @throws \DomainException for an archived firm, which issues nothing new
     */
    public function change(Party $party, LegalEntity $firm, InvoicingDefaults $defaults): void
    {
        if ($firm->isArchived()) {
            throw new \DomainException('An archived firm has no settings to change.');
        }

        $stored = $this->settings->find($party, $firm);
        if ($stored instanceof PartyFirmSettings) {
            $stored->change($defaults);
        } elseif (!$defaults->equals($party->invoicingDefaults())) {
            $this->settings->add(new PartyFirmSettings($party, $firm, $defaults));
        }
    }
}
