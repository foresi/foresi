<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Party\Integration\Documents;

use Foresi\Party\Contract\ContactTabs;
use Foresi\Party\Domain\Address;
use Foresi\Party\Integration\Documents\AddressLines;
use Foresi\Party\Integration\Documents\DocumentsContactTab;
use Foresi\Shared\Domain\Id;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The small pieces Party gives Documents: an address line by line, and the
 * Documents tab, only in the tabs of a contact.
 */
final class DocumentsPiecesTest extends KernelTestCase
{
    #[Test]
    public function anAddressIsTrimmedLineByLineAndSkipsEmptyLines(): void
    {
        self::assertSame(
            [['line' => 'Example Street 1'], ['line' => '100 00 Example City'], ['line' => 'CZ']],
            AddressLines::of(new Address(' Example Street 1 ', 'Example City ', ' 100 00', ' CZ ')),
        );
        self::assertSame([['line' => 'CZ']], AddressLines::of(new Address(' ', '', '', 'CZ')));
    }

    #[Test]
    public function theDocumentsTabIsOnlyAContactTab(): void
    {
        $tab = self::getContainer()->get(DocumentsContactTab::class);

        self::assertSame([], [...$tab->items('invoice.tabs', ['contactId' => Id::generate()])]);
        self::assertSame([], [...$tab->items(ContactTabs::SLOT, [])]);
        self::assertCount(1, [...$tab->items(ContactTabs::SLOT, ['contactId' => Id::generate()])]);
    }
}
