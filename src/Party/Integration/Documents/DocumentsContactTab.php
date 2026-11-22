<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Integration\Documents;

use Foresi\Documents\Contract\RecordDocuments;
use Foresi\Party\Contract\ContactTabs;
use Foresi\Ui\Contract\Slot\SlotItem;
use Foresi\Ui\Contract\Slot\SlotProvider;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The Documents tab of a contact: the documents linked to it, with New document
 * that opens the creation dialog with the contact linked (RecordDocuments).
 */
final readonly class DocumentsContactTab implements SlotProvider
{
    /** After a deals tab at 100, so the sales records come first. */
    private const int WEIGHT = 110;

    public function __construct(private UrlGeneratorInterface $urls, private TranslatorInterface $translator)
    {
    }

    public function items(string $slot, array $context): iterable
    {
        $contactId = $context['contactId'] ?? null;
        if (ContactTabs::SLOT !== $slot || !\is_string($contactId)) {
            return [];
        }

        return [new SlotItem(data: [
            'key' => 'documents',
            'label' => $this->translator->trans('party.tab.documents'),
            'href' => $this->urls->generate(RecordDocuments::ROUTE, ['kind' => ContactLinkTarget::KIND, 'id' => $contactId]),
        ], weight: self::WEIGHT)];
    }
}
