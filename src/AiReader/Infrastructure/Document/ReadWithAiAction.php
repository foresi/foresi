<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\AiReader\Infrastructure\Document;

use Foresi\AiReader\Application\AiReaderSettings;
use Foresi\Purchasing\Contract\PurchasingSlots;
use Foresi\Ui\Contract\Slot\SlotItem;
use Foresi\Ui\Contract\Slot\SlotProvider;

/**
 * "Read with AI" in the menu of the page of a draft, only when the reader is set up and runs only when a person
 * asks for it. It posts to Read again, which always runs the fallback readers.
 */
final readonly class ReadWithAiAction implements SlotProvider
{
    public const string TEMPLATE = '@AiReader/document/_read_with_ai.html.twig';

    private const int WEIGHT = 10;

    public function __construct(private AiReaderSettings $settings)
    {
    }

    public function items(string $slot, array $context): iterable
    {
        if (PurchasingSlots::DOCUMENT_ACTIONS !== $slot || !$this->settings->isConfigured() || $this->settings->automatic()) {
            return [];
        }

        return [new SlotItem(self::TEMPLATE, [], self::WEIGHT)];
    }
}
