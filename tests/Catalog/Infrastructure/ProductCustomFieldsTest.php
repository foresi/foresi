<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Catalog\Infrastructure;

use Foresi\Catalog\Contract\BillingKind;
use Foresi\Catalog\Contract\Field\ProductExtension;
use Foresi\Catalog\Domain\Product;
use Foresi\Catalog\Domain\ProductTerms;
use Foresi\Identity\Domain\Role;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Application\View\WorkingFilter;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Tests\Shared\Fixtures\Field\LentFields;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The custom fields of a product (ADR 0045) in the list of products: a column, a filter
 * and the search, with the test-only fields lent to the products.
 */
final class ProductCustomFieldsTest extends WebTestCase
{
    public function testTheListShowsFiltersAndSearchesTheFields(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $browser = new SignedInBrowser($client, self::getContainer()->get('doctrine.orm.entity_manager'));
        $fields = LentFields::lend(self::getContainer(), ProductExtension::RECORD);
        $browser->seed(role: Role::Owner);
        $em = $browser->em();
        $em->persist(new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, ''));
        $products = [];
        foreach (['Roof audit', 'Wall inspection', 'Window repair'] as $name) {
            $em->persist($products[$name] = new Product(new ProductTerms($name, '', 'piece', null, BillingKind::OneOff, null, null, null), new \DateTimeImmutable('2026-11-25')));
        }
        $em->flush();
        $browser->signIn();
        $fields->write(ProductExtension::RECORD, $products['Roof audit']->id(), ['test_fields_code' => 'RA-17', 'test_fields_flag' => true]);
        $fields->write(ProductExtension::RECORD, $products['Wall inspection']->id(), ['test_fields_flag' => false]);
        $em->flush();

        $flagged = $client->request('GET', '/catalog/products?cols=name,test_fields_code&f='.WorkingFilter::encode(ViewFilter::all(new Condition('test_fields_flag', 'is', true))))->filter('table')->text();
        $found = $client->request('GET', '/catalog/products?q=ra-17')->filter('table')->text();

        self::assertStringContainsString('RA-17', $flagged);
        self::assertStringNotContainsString('Wall inspection', $flagged);
        self::assertStringContainsString('Roof audit', $found);
        self::assertStringNotContainsString('Window repair', $found);
    }
}
