<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Slot;

use Foresi\Party\Contract\FirmTabs;
use Foresi\Ui\Contract\Slot\SlotItem;
use Foresi\Ui\Contract\Slot\SlotProvider;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The invoicing and mail settings of a firm, as tabs of the firm.
 */
final readonly class FirmInvoicingTabs implements SlotProvider
{
    public function __construct(private UrlGeneratorInterface $urls, private TranslatorInterface $translator)
    {
    }

    public function items(string $slot, array $context): iterable
    {
        if (FirmTabs::SLOT !== $slot || !\is_string($context['firmId'] ?? null)) {
            return [];
        }
        $id = ['id' => $context['firmId']];

        return [
            new SlotItem(data: ['key' => 'invoicing', 'label' => $this->translator->trans('firm.tab.invoicing'), 'href' => $this->urls->generate('app_firm_invoicing', $id)], weight: 10),
            new SlotItem(data: ['key' => 'mail', 'label' => $this->translator->trans('firm.tab.mail'), 'href' => $this->urls->generate('app_firm_mail', $id)], weight: 20),
        ];
    }
}
