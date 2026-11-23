<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Integration\Documents;

use Foresi\Sales\Contract\SalesRecords;
use Foresi\Sales\Contract\SalesSlots;
use Foresi\Ui\Contract\Slot\SlotItem;
use Foresi\Ui\Contract\Slot\SlotProvider;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "New document" in the header of a deal's page: the creation dialog of
 * Documents with the deal and its client linked (RecordDocuments), so an NDA
 * or an offer fills in {{ deal.* }} and {{ client.* }}.
 */
final readonly class NewDocumentDealAction implements SlotProvider
{
    public const string KEY = 'new-document';

    /** The route of the creation dialog of Documents (RecordDocuments names it). */
    private const string NEW_ROUTE = 'app_document_new';

    /** The record kind of a contact of the address book, as Party links it. */
    private const string CONTACT = 'contact';

    private const int WEIGHT = 20;

    public function __construct(private UrlGeneratorInterface $urls, private TranslatorInterface $translator)
    {
    }

    public function items(string $slot, array $context): iterable
    {
        $dealId = $context['dealId'] ?? null;
        $clientId = $context['clientId'] ?? null;
        if (SalesSlots::DEAL_ACTIONS !== $slot || !\is_string($dealId) || !\is_string($clientId)) {
            return [];
        }

        return [new SlotItem(data: [
            'key' => self::KEY,
            'label' => $this->translator->trans('sales.deal.new_document'),
            'icon' => 'file-text',
            'dialog' => true,
            'href' => $this->urls->generate(self::NEW_ROUTE, [
                'link_kind' => SalesRecords::KIND,
                'link_id' => $dealId,
                'links' => [self::CONTACT.':'.$clientId],
            ]),
        ], weight: self::WEIGHT)];
    }
}
