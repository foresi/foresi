<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Party\Integration\Banking;

use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The firm page with the tabs of Invoicing (which Banking requires) and Banking
 * after the address book's own: each opens inside the firm, which stays marked
 * in the sidebar.
 */
final class FirmTabsTest extends WebTestCase
{
    public function testTheFirmPageHasTabsForDetailsInvoicingMailAndBankAndKeepsTheFirmMarkedInTheSidebar(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        $browser = new SignedInBrowser($client, $em);
        $browser->seed('en');
        $browser->signIn();
        $em->persist(new LegalEntity('Example Firm', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, ''));
        $em->flush();

        $crawler = $client->request('GET', '/settings/firm');

        self::assertSame(['Details', 'VAT', 'Invoicing', 'E-mail', 'Bank'], $crawler->filter('main nav[aria-label="Firm sections"] a')->each(static fn ($a): string => trim($a->text())));
        self::assertSame(['/settings/firm', '/settings/firm/vat', '/settings/firm/invoicing', '/settings/firm/mail', '/settings/firm/bank'], $crawler->filter('main nav[aria-label="Firm sections"] a')->each(static fn ($a): string => (string) $a->attr('href')));
        self::assertSame('Details', trim($crawler->filter('main nav[aria-label="Firm sections"] [aria-current="page"]')->text()));
        self::assertSame('/settings/firm', $crawler->filter('#sidebar [aria-current="page"]')->attr('href'));

        $crawler = $client->request('GET', '/settings/firm/invoicing');

        self::assertResponseIsSuccessful();
        self::assertSame('Invoicing', trim($crawler->filter('main nav[aria-label="Firm sections"] [aria-current="page"]')->text()));
        self::assertSame('/settings/firm', $crawler->filter('#sidebar [aria-current="page"]')->attr('href'));
    }
}
