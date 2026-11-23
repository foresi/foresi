<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\CashFlow\Integration\Purchasing;

use Foresi\CashFlow\Contract\DocumentPairings;
use Foresi\CashFlow\Contract\PairingState;
use Foresi\CashFlow\Contract\PairingView;
use Foresi\Purchasing\Contract\PurchasingSlots;
use Foresi\Purchasing\Integration\CashFlow\PairingPanel;
use PHPUnit\Framework\TestCase;

/**
 * The Plan panel is offered in the sections of a received document, for a document that is named.
 */
final class PairingPanelTest extends TestCase
{
    public function testThePanelIsOfferedForADocumentInItsSections(): void
    {
        $items = iterator_to_array($this->panel()->items(PurchasingSlots::DOCUMENT_SECTIONS, ['documentId' => 'doc-1']), false);

        self::assertCount(1, $items);
    }

    public function testNothingIsOfferedInAnotherSlotOrForNoDocument(): void
    {
        self::assertSame([], $this->panel()->items('purchasing.other_slot', ['documentId' => 'doc-1']));
        self::assertSame([], $this->panel()->items(PurchasingSlots::DOCUMENT_SECTIONS, []));
        self::assertSame([], $this->panel()->items(PurchasingSlots::DOCUMENT_SECTIONS, ['documentId' => 7]));
    }

    private function panel(): PairingPanel
    {
        $pairings = self::createStub(DocumentPairings::class);
        $pairings->method('view')->willReturn(new PairingView(PairingState::Proposed, null, []));

        return new PairingPanel($pairings);
    }
}
