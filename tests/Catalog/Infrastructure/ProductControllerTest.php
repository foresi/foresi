<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Catalog\Infrastructure;

use Foresi\Catalog\Contract\BillingKind;
use Foresi\Catalog\Domain\Product;
use Foresi\Identity\Domain\Role;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Domain\Schedule\RecurrenceUnit;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Catalog → Products under Sales: the list on saved views, "+ New product"
 * in a dialog where the billing kind reveals the period, the product's record
 * with Edit in a dialog and Archive and Restore in its header menu.
 */
final class ProductControllerTest extends WebTestCase
{
    private const string HEADER = 'HTTP_X_FORESI_DIALOG';

    private KernelBrowser $client;
    private SignedInBrowser $browser;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
    }

    public function testTheListIsUnderSalesAndStartsEmpty(): void
    {
        $this->signIn();
        $crawler = $this->client->request('GET', '/catalog/products');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Products');
        self::assertSame('/catalog/products/new', $crawler->filter('header #product-new[data-dialog]')->attr('href'));
        self::assertSelectorTextContains('body', 'No products yet');
        self::assertGreaterThan(0, $crawler->filter('nav a[href="/catalog/products"][aria-current="page"]')->count());
        self::assertSame(['All', 'One-off', 'Recurring', 'Archived'], $crawler->filter('nav[aria-label="Views of the products"] a')->each(static fn (Crawler $tab): string => trim($tab->text())));
        // Without JavaScript the dialog is a page of its own.
        $this->client->request('GET', '/catalog/products/new');
        self::assertResponseIsSuccessful();
    }

    public function testCreatesARecurringProductInTheDialog(): void
    {
        $this->signIn();
        $crawler = $this->client->request('GET', '/catalog/products/new', server: [self::HEADER => '1']);
        self::assertCount(1, $crawler->filter('dialog#dialog-page [data-controller="product-billing"] [data-product-billing-target="period"]'));
        $values = $crawler->filter('#product-form')->form()->getPhpValues();

        $this->client->request('POST', '/catalog/products/new', ['name' => 'Marketing retainer', 'unit' => 'month', 'unitPrice' => '25 000,50', 'billingKind' => 'recurring', 'interval' => '1', 'recurrenceUnit' => 'month', 'cycles' => '12'] + $values, server: [self::HEADER => '1']);
        // The dialog is told where the new product is, and follows it.
        $product = $this->product();
        self::assertSame(['location' => '/catalog/products/'.$product->id()], json_decode((string) $this->client->getResponse()->getContent(), true));
        self::assertSame([BillingKind::Recurring, RecurrenceUnit::Month, 1, 12, 2500050], [$product->billingKind(), $product->recurrenceUnit(), $product->interval(), $product->cycles(), $product->unitPrice()?->minor()]);

        $crawler = $this->client->request('GET', '/catalog/products');
        self::assertSelectorTextContains('table', 'Marketing retainer');
        self::assertSelectorTextContains('table', 'Every month, 12 times');
        self::assertSame('/catalog/products/'.$product->id(), $crawler->filter('table [data-row-href], table a[href^="/catalog/products/"]')->first()->attr('data-row-href') ?? $crawler->filter('table a[href^="/catalog/products/"]')->first()->attr('href'));
    }

    public function testARefusedFieldIsShownAndAOneOffProductIgnoresThePeriod(): void
    {
        $this->signIn();
        $values = $this->client->request('GET', '/catalog/products/new')->filter('#product-form')->form()->getPhpValues();

        $this->client->request('POST', '/catalog/products/new', ['name' => '', 'billingKind' => 'one_off'] + $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#product-form', 'Enter the name.');

        // The period fields are sent without JavaScript; a one-off product ignores them.
        $this->client->request('POST', '/catalog/products/new', ['name' => 'Roof audit', 'billingKind' => 'one_off', 'interval' => '3', 'cycles' => '4'] + $values);
        self::assertResponseRedirects();
        $product = $this->product();
        self::assertSame([BillingKind::OneOff, null, null, null], [$product->billingKind(), $product->recurrenceUnit(), $product->interval(), $product->cycles()]);
    }

    public function testEditsArchivesAndRestoresFromTheRecord(): void
    {
        $this->signIn();
        $values = $this->client->request('GET', '/catalog/products/new')->filter('#product-form')->form()->getPhpValues();
        $this->client->request('POST', '/catalog/products/new', ['name' => 'Roof audit', 'billingKind' => 'one_off'] + $values);
        $id = $this->product()->id();

        $crawler = $this->client->request('GET', '/catalog/products/'.$id);
        self::assertResponseIsSuccessful();
        self::assertSame('/catalog/products/'.$id.'/edit', $crawler->filter('[data-product-edit]')->attr('href'));
        $edit = $this->client->request('GET', '/catalog/products/'.$id.'/edit', server: [self::HEADER => '1'])->filter('#product-form')->form()->getPhpValues();
        $this->client->request('POST', '/catalog/products/'.$id.'/edit', ['name' => 'Roof inspection', 'unitPrice' => '9000'] + $edit, server: [self::HEADER => '1']);
        self::assertResponseIsSuccessful();
        self::assertSame(['Roof inspection', 900000], [$this->product()->name(), $this->product()->unitPrice()?->minor()]);

        $crawler = $this->client->request('GET', '/catalog/products/'.$id);
        $this->client->submit($crawler->filter('[data-product-archive]')->form());
        self::assertTrue($this->product()->isArchived());
        $crawler = $this->client->request('GET', '/catalog/products');
        self::assertSelectorTextNotContains('body', 'Roof inspection');
        $this->client->request('GET', '/catalog/products?view=archived');
        self::assertSelectorTextContains('table', 'Roof inspection');

        $crawler = $this->client->request('GET', '/catalog/products/'.$id);
        $this->client->submit($crawler->filter('[data-product-restore]')->form());
        self::assertFalse($this->product()->isArchived());
    }

    public function testAGuestIsRefused(): void
    {
        $this->signIn(Role::Guest);

        $this->client->request('GET', '/catalog/products');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/catalog/products/new');
        self::assertResponseStatusCodeSame(403);
    }

    private function signIn(Role $role = Role::Owner): void
    {
        $this->browser->seed(role: $role);
        // A firm, so the instance is past its first-run setup.
        $em = $this->browser->em();
        $em->persist(new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, ''));
        $em->flush();
        $this->browser->signIn();
    }

    private function product(): Product
    {
        $em = $this->browser->em();
        $em->clear();
        $product = $em->getRepository(Product::class)->findOneBy([]);
        self::assertInstanceOf(Product::class, $product);

        return $product;
    }
}
