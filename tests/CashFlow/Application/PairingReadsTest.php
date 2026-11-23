<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\CashFlow\Application;

use Foresi\CashFlow\Application\CashFlowAccess;
use Foresi\CashFlow\Application\CashFlowRefused;
use Foresi\CashFlow\Application\CashFlowStore;
use Foresi\CashFlow\Application\PairingReads;
use Foresi\CashFlow\Domain\DocumentPairing;
use Foresi\Tests\CashFlow\Support\CashFlowTestCase;

/**
 * The list of pairings the API and MCP give: for the staff only, by state and by planned item.
 */
final class PairingReadsTest extends CashFlowTestCase
{
    public function testAGuestCannotListThePairings(): void
    {
        $this->signIn($this->guest);

        try {
            $this->reads()->list(null, null);
            self::fail('A guest listed the pairings.');
        } catch (CashFlowRefused $refused) {
            self::assertSame('forbidden', $refused->reason());
        }
    }

    public function testABadStateIsRefusedWithTheStatesThereAre(): void
    {
        try {
            $this->reads()->list('maybe', null);
            self::fail('A bad state was accepted.');
        } catch (CashFlowRefused $refused) {
            self::assertSame('state_invalid', $refused->reason());
            self::assertSame('The state is one of: auto, confirmed, proposed, declined.', $refused->getMessage());
        }
    }

    public function testThePairingsOfOneItemAreAListOfThoseThatPointAtItOrListIt(): void
    {
        $a = $this->planned(on: '2026-05-20', repeat: 'month', name: 'Cloud Host', counterpartyId: $this->customer->id());
        $b = $this->planned(on: '2026-05-25', repeat: 'month', name: 'Voice API', counterpartyId: $this->customer->id());
        $now = new \DateTimeImmutable('2026-11-17 10:00:00');
        $this->em->persist(DocumentPairing::auto('received_document', 'doc-1', $this->customer, $a, new \DateTimeImmutable('2026-05-20'), $now));
        $this->em->persist(DocumentPairing::auto('received_document', 'doc-2', $this->customer, $b, new \DateTimeImmutable('2026-05-25'), $now));
        $this->em->flush();

        $rows = $this->reads()->list(null, $b->id());

        self::assertSame([0], array_keys($rows));
        self::assertSame(['doc-2'], array_map(static fn (DocumentPairing $row): string => $row->subjectId(), $rows));
    }

    private function reads(): PairingReads
    {
        $c = self::getContainer();

        return new PairingReads($c->get(CashFlowAccess::class), $c->get(CashFlowStore::class));
    }
}
