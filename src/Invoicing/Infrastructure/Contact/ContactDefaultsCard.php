<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Contact;

use Foresi\Invoicing\Contract\PartyInvoicingDefaults;
use Foresi\Party\Contract\ContactCards;
use Foresi\Ui\Contract\Slot\SlotItem;
use Foresi\Ui\Contract\Slot\SlotProvider;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The card of a contact's detail with what invoices to it start with: the due
 * period, the currency, the document language and the reverse charge.
 */
final readonly class ContactDefaultsCard implements SlotProvider
{
    public function __construct(private PartyInvoicingDefaults $defaults, private TranslatorInterface $translator)
    {
    }

    public function items(string $slot, array $context): iterable
    {
        $contactId = $context['contactId'] ?? null;
        if (ContactCards::SLOT !== $slot || !\is_string($contactId)) {
            return [];
        }
        $defaults = $this->defaults->of($contactId);
        $label = fn (string $key): string => $this->translator->trans('invoice.contact_defaults.'.$key);

        return [new SlotItem('@Invoicing/contact/_defaults_card.html.twig', ['items' => [
            ['label' => $label('due_days'), 'value' => $this->translator->trans('invoice.contact_defaults.days', ['count' => $defaults->dueDays])],
            ['label' => $label('currency'), 'value' => $defaults->currency->value],
            ['label' => $label('language'), 'value' => $this->translator->trans('locale.'.$defaults->language->value)],
            ['label' => $label('reverse_charge'), 'value' => $label($defaults->reverseCharge ? 'yes' : 'no')],
        ]])];
    }
}
