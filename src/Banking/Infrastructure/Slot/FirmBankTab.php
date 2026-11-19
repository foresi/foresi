<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Infrastructure\Slot;

use Foresi\Party\Contract\FirmTabs;
use Foresi\Ui\Contract\Slot\SlotItem;
use Foresi\Ui\Contract\Slot\SlotProvider;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The bank accounts of a firm, as a tab of the firm.
 */
final readonly class FirmBankTab implements SlotProvider
{
    public function __construct(private UrlGeneratorInterface $urls, private TranslatorInterface $translator)
    {
    }

    public function items(string $slot, array $context): iterable
    {
        if (FirmTabs::SLOT !== $slot || !\is_string($context['firmId'] ?? null)) {
            return [];
        }

        return [new SlotItem(data: ['key' => 'bank', 'label' => $this->translator->trans('firm.tab.bank'), 'href' => $this->urls->generate('app_firm_bank')], weight: 30)];
    }
}
