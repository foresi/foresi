<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Catalog\Infrastructure;

use Foresi\Catalog\Application\CreateProductInput;
use Foresi\Catalog\Application\ProductWrites;
use Foresi\Catalog\Application\UpdateProductInput;
use Foresi\Catalog\Contract\Field\ProductExtension;
use Foresi\Events\Infrastructure\Dbal\EventLogTable;
use Foresi\Identity\Domain\Role;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Application\Field\FieldRefused;
use Foresi\Shared\Contract\Field\Fields;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Tests\Shared\Fixtures\Field\LentFields;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The custom fields of a product (ADR 0045) on its screens, with the test-only fields
 * lent to the products and their code required: the form asks for every field under
 * "Additional details", a new product needs the required one, the save goes through
 * ProductWrites with the changed keys in catalog.product.updated, and the detail shows
 * the values in their card.
 */
final class ProductCustomFieldScreensTest extends WebTestCase
{
    private const array DIALOG = ['HTTP_X_FORESI_DIALOG' => '1'];

    private KernelBrowser $client;
    private SignedInBrowser $browser;
    private Fields $fields;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
        $this->fields = LentFields::lend(self::getContainer(), ProductExtension::RECORD, ['test_fields_code']);
        $this->browser->seed(role: Role::Owner);
        $this->browser->em()->persist(new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, ''));
        $this->browser->em()->flush();
        $this->browser->signIn();
    }

    public function testTheFormAsksForEveryFieldAndANewProductNeedsTheRequiredOne(): void
    {
        $crawler = $this->dialog('/catalog/products/new');
        $section = $crawler->filter('#product-form [data-custom-fields-section]');
        self::assertStringContainsString('Additional details', $section->filter('h3')->text());
        self::assertSame('date', $section->filter('input[name="custom_fields[test_fields_due]"]')->attr('type'));
        self::assertNotNull($section->filter('#custom-field-test-fields-code')->attr('required'));
        $form = $crawler->filter('#product-form')->form(['name' => 'Roof audit', 'unit' => 'piece', 'billingKind' => 'one_off']);

        $crawler = $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('true', $crawler->filter('#custom-field-test-fields-code')->attr('aria-invalid'));
        self::assertFalse($this->browser->em()->getConnection()->fetchOne('SELECT id FROM catalog_product'));

        $this->client->submit($form, ['custom_fields[test_fields_code]' => 'RA-1', 'custom_fields[test_fields_due]' => '2027-01-31']);
        self::assertResponseRedirects();
        $id = $this->productId();
        self::assertSame('RA-1', $this->fields->value(ProductExtension::RECORD, $id, 'test_fields_code'));
        self::assertEquals(new \DateTimeImmutable('2027-01-31'), $this->fields->value(ProductExtension::RECORD, $id, 'test_fields_due'));
    }

    public function testEditSavesTheFieldsAndTheDetailShowsThem(): void
    {
        $form = $this->dialog('/catalog/products/new')->filter('#product-form')->form(['name' => 'Roof audit', 'unit' => 'piece', 'billingKind' => 'one_off', 'custom_fields[test_fields_code]' => 'RA-1']);
        $this->client->submit($form);
        $id = $this->productId();

        $crawler = $this->dialog('/catalog/products/'.$id.'/edit');
        self::assertSame('RA-1', $crawler->filter('#custom-field-test-fields-code')->attr('value'));
        $this->client->submit($crawler->filter('#product-form')->form(['custom_fields[test_fields_size]' => 'small', 'custom_fields[test_fields_flag]' => '1']));

        self::assertResponseRedirects('/catalog/products/'.$id);
        self::assertSame(['test_fields_flag', 'test_fields_size'], $this->updatedKeys($id), 'In the order the fields are declared.');
        $crawler = $this->client->request('GET', '/catalog/products/'.$id);
        self::assertResponseIsSuccessful();
        $card = $crawler->filter('[data-custom-fields]');
        self::assertStringContainsString('Additional details', $crawler->filter('main')->text());
        self::assertStringContainsString('test.size.small', $card->text());
        self::assertStringContainsString('RA-1', $card->text());
    }

    public function testTheWritesAskForTheRequiredFieldOnlyOfANewProduct(): void
    {
        $writes = self::getContainer()->get(ProductWrites::class);
        try {
            $writes->create(new CreateProductInput('Roof audit', unit: 'piece'), []);
            self::fail('A new product needs its required field.');
        } catch (FieldRefused $refused) {
            self::assertSame(['test_fields_code', FieldRefused::REQUIRED], [$refused->key, $refused->translationKey]);
        }

        $product = $writes->create(new CreateProductInput('Roof audit', unit: 'piece'), ['test_fields_code' => 'RA-1']);
        $writes->update(new UpdateProductInput($product->id()), ['test_fields_code' => null]);

        self::assertNull($this->fields->value(ProductExtension::RECORD, $product->id(), 'test_fields_code'), 'A change may clear it.');
    }

    private function productId(): string
    {
        $id = $this->browser->em()->getConnection()->fetchOne('SELECT id FROM catalog_product');
        self::assertIsString($id);

        return $id;
    }

    private function dialog(string $url): Crawler
    {
        $this->client->request('GET', $url, server: self::DIALOG);
        self::assertResponseIsSuccessful();

        return new Crawler((string) $this->client->getResponse()->getContent(), 'http://localhost'.$url);
    }

    /**
     * @return list<mixed> the custom fields the last catalog.product.updated of the product names
     */
    private function updatedKeys(string $id): array
    {
        $data = $this->browser->em()->getConnection()->fetchOne(
            'SELECT '.EventLogTable::DATA.' FROM '.EventLogTable::LOG.' WHERE '.EventLogTable::NAME.' = ? AND '.EventLogTable::SUBJECT_ID.' = ? ORDER BY '.EventLogTable::ID.' DESC',
            ['catalog.product.updated', $id],
        );
        self::assertIsString($data);
        $payload = json_decode($data, true);
        self::assertIsArray($payload);
        self::assertIsArray($payload['custom_fields']);

        return array_values($payload['custom_fields']);
    }
}
