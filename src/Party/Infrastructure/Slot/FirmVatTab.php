<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Infrastructure\Slot;

use Foresi\Party\Contract\FirmTabs;
use Foresi\Ui\Contract\Slot\SlotItem;
use Foresi\Ui\Contract\Slot\SlotProvider;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The VAT statuses of the firm over time, as a tab of the firm right after its details.
 */
final readonly class FirmVatTab implements SlotProvider
{
    public function __construct(private UrlGeneratorInterface $urls, private TranslatorInterface $translator)
    {
    }

    public function items(string $slot, array $context): iterable
    {
        if (FirmTabs::SLOT !== $slot) {
            return [];
        }

        return [new SlotItem(data: ['key' => 'vat', 'label' => $this->translator->trans('firm.tab.vat'), 'href' => $this->urls->generate('app_firm_vat')], weight: 5)];
    }
}
