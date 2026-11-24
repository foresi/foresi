<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Infrastructure\Party;

use Foresi\Margin\Application\MarginAccess;
use Foresi\Margin\Domain\MarginRefused;
use Foresi\Party\Contract\ContactTabs;
use Foresi\Ui\Contract\Slot\SlotItem;
use Foresi\Ui\Contract\Slot\SlotProvider;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The Margin tab of a contact, for the owners and members: who bears the costs of this contact as a supplier by
 * default. A guest never sees it.
 */
final readonly class MarginContactTab implements SlotProvider
{
    public const string KEY = 'margin';

    /** After the documents at 110. */
    public const int WEIGHT = 120;

    public function __construct(private MarginAccess $access, private UrlGeneratorInterface $urls, private TranslatorInterface $translator)
    {
    }

    public function items(string $slot, array $context): iterable
    {
        $contactId = $context['contactId'] ?? null;
        if (ContactTabs::SLOT !== $slot || !\is_string($contactId)) {
            return [];
        }
        try {
            $this->access->denyUnlessStaff();
        } catch (MarginRefused) {
            return [];
        }

        return [new SlotItem(data: [
            'key' => self::KEY,
            'label' => $this->translator->trans('margin.supplier.tab'),
            'href' => $this->urls->generate(SupplierDefaultController::ROUTE, ['id' => $contactId]),
        ], weight: self::WEIGHT)];
    }
}
