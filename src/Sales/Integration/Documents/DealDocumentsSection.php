<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Integration\Documents;

use Foresi\Documents\Contract\RecordDocuments;
use Foresi\Sales\Contract\SalesRecords;
use Foresi\Sales\Contract\SalesSlots;
use Foresi\Ui\Contract\Slot\SlotItem;
use Foresi\Ui\Contract\Slot\SlotProvider;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The Documents section of a deal's page: the documents linked to the deal,
 * loaded by a lazy frame from RecordDocuments, as Pipedrive lists a deal's
 * documents on the deal.
 */
final readonly class DealDocumentsSection implements SlotProvider
{
    public const string KEY = 'documents';

    /** After the activities at 10: what happened with the deal comes first. */
    private const int WEIGHT = 20;

    public function __construct(private UrlGeneratorInterface $urls, private TranslatorInterface $translator)
    {
    }

    public function items(string $slot, array $context): iterable
    {
        $dealId = $context['dealId'] ?? null;
        if (SalesSlots::DEAL_SECTIONS !== $slot || !\is_string($dealId)) {
            return [];
        }

        return [new SlotItem(data: [
            'key' => self::KEY,
            'label' => $this->translator->trans('sales.deal.tab.documents'),
            'href' => $this->urls->generate(RecordDocuments::ROUTE, ['kind' => SalesRecords::KIND, 'id' => $dealId]),
        ], weight: self::WEIGHT)];
    }
}
