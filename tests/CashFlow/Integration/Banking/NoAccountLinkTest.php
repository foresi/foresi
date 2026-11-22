<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\CashFlow\Integration\Banking;

use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

/**
 * With Banking, a firm without a bank account is sent from the Cash flow page
 * to the bank tab of the firm, where the accounts are added.
 */
final class NoAccountLinkTest extends WebTestCase
{
    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testTheEmptyPageLinksToTheBankTab(): void
    {
        Clock::set(new MockClock('2026-11-19 10:00:00', 'UTC'));
        $client = self::createClient();
        $client->disableReboot();
        $browser = new SignedInBrowser($client, self::getContainer()->get('doctrine.orm.entity_manager'));
        $browser->seed();
        $browser->signIn();
        $em = $browser->em();
        $em->persist(new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, ''));
        $em->flush();

        $crawler = $client->request('GET', '/cash-flow');

        self::assertResponseIsSuccessful();
        self::assertSame('/settings/firm/bank', $crawler->filter('[data-cash-flow-empty] a')->attr('href'));
    }
}
