<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Sales\Infrastructure;

use Foresi\Tests\Sales\Support\SalesScreenTestCase;

/**
 * A deal's page shows what Documents and Activities add only where they are
 * installed: Sales alone (bin/module-subset) shows no New document, no Log or
 * Plan activity and none of their sections, since its integrations are not
 * registered; tests/Sales/Integration tests them where those modules are.
 */
final class DealIntegrationsTest extends SalesScreenTestCase
{
    public function testTheDealShowsWhatTheInstalledModulesAdd(): void
    {
        $bundles = self::getContainer()->getParameter('kernel.bundles');
        $documents = \in_array('Foresi\Documents\DocumentsBundle', $bundles, true);
        $activities = \in_array('Foresi\Activities\ActivitiesBundle', $bundles, true);
        $deal = $this->newDeal('Roof audit', [0]);

        $crawler = $this->client->request('GET', '/sales/deals/'.$deal->id());

        self::assertResponseIsSuccessful();
        self::assertCount($documents ? 1 : 0, $crawler->filter('[data-deal-action="new-document"]'));
        self::assertCount($documents ? 1 : 0, $crawler->filter('[data-deal-section="documents"]'));
        self::assertCount($activities ? 2 : 0, $crawler->filter('[data-deal-action$="-activity"]'));
        self::assertCount($activities ? 1 : 0, $crawler->filter('[data-deal-section="activities"]'));
        // Sales' own sections are there either way.
        self::assertCount(1, $crawler->filter('[data-deal-lines]'));
        self::assertCount(1, $crawler->filter('[data-deal-links]'));
    }
}
