<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Banking\Infrastructure;

use Foresi\Banking\Contract\MovementTrust;
use Foresi\Tests\Banking\Support\BankScreenTestCase;

/**
 * The bank screens work whether or not a module pays documents from movements:
 * with Invoicing a waiting movement offers to pair it; without it the movement
 * says that nothing pairs it, and neither the list nor the dialog offers pairing.
 */
final class BankPairingScreenTest extends BankScreenTestCase
{
    public function testPairingIsOfferedOnlyWhenAModulePairsMovements(): void
    {
        $pairs = \in_array(\Foresi\Invoicing\InvoicingBundle::class, self::getContainer()->getParameter('kernel.bundles'), true);
        $this->signedIn();
        $movement = $this->movement('1210.00', '20260001', trust: MovementTrust::Verified);

        $crawler = $this->page('/bank');

        self::assertSame($pairs ? 'no_invoice' : 'no_pairing', $this->movementRow($movement)['unmatched_reason']);
        self::assertCount($pairs ? 1 : 0, $crawler->filter('tbody a[role="menuitem"][href*="/pair"][data-dialog]'));
        self::assertCount(0, $crawler->filter('tbody a[role="menuitem"][href*="/unpair"]'));

        $this->client->request('GET', '/bank/movements/'.$movement.'/pair');
        if ($pairs) {
            self::assertResponseIsSuccessful();

            return;
        }
        self::assertResponseRedirects('/bank');
        self::assertStringContainsString('no invoicing is installed', $this->client->followRedirect()->text());
        self::assertSame('no_pairing', $this->movementRow($movement)['unmatched_reason'], 'nothing was stored');
    }
}
