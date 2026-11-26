<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Sales\Infrastructure;

use Foresi\Events\Infrastructure\Dbal\EventLogTable;
use Foresi\Sales\Application\Api\CreateDealInput;
use Foresi\Sales\Application\Api\UpdateDealInput;
use Foresi\Sales\Application\DealWrites;
use Foresi\Sales\Contract\Field\DealExtension;
use Foresi\Sales\Domain\Deal;
use Foresi\Shared\Application\Field\FieldRefused;
use Foresi\Shared\Contract\Field\Fields;
use Foresi\Tests\Sales\Support\SalesScreenTestCase;
use Foresi\Tests\Shared\Fixtures\Field\LentFields;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The custom fields of a deal (ADR 0045) on its screens, with the test-only fields lent
 * to the deals and their code required: New and Edit ask for every field under
 * "Additional details", a new deal needs the required one, the save goes through
 * DealWrites with the changed keys in sales.deal.updated, and the side column of the
 * deal shows the values.
 */
final class DealCustomFieldScreensTest extends SalesScreenTestCase
{
    private Fields $fields;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fields = LentFields::lend(self::getContainer(), DealExtension::RECORD, ['test_fields_code']);
    }

    public function testNewAsksForEveryFieldAndANewDealNeedsTheRequiredOne(): void
    {
        $crawler = $this->dialog('/sales/deals/new?client='.$this->company->id());
        $section = $crawler->filter('#deal-form [data-custom-fields-section]');
        self::assertStringContainsString('Additional details', $section->filter('h3')->text());
        self::assertCount(1, $section->filter('select[name="custom_fields[test_fields_size]"]'));
        self::assertCount(1, $section->filter('textarea[name="custom_fields[test_fields_long_note]"]'));
        self::assertNotNull($section->filter('#custom-field-test-fields-code')->attr('required'));
        $form = $crawler->filter('#deal-form')->form(['title' => 'Roof audit']);

        $crawler = $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('true', $crawler->filter('#custom-field-test-fields-code')->attr('aria-invalid'));
        self::assertNull($this->em()->getRepository(Deal::class)->findOneBy(['title' => 'Roof audit']));

        $values = $form->getPhpValues();
        $values['custom_fields'] = ['test_fields_code' => 'RA-1', 'test_fields_tags' => ['red']];
        $this->client->request('POST', $form->getUri(), $values);
        self::assertResponseRedirects();
        $deal = $this->deal('Roof audit');
        self::assertSame('RA-1', $this->fields->value(DealExtension::RECORD, $deal, 'test_fields_code'));
        self::assertSame(['red'], $this->fields->value(DealExtension::RECORD, $deal, 'test_fields_tags'));
    }

    public function testEditSavesTheFieldsAndTheEventNamesTheChangedOnes(): void
    {
        $deal = $this->created('Roof audit');

        $crawler = $this->dialog('/sales/deals/'.$deal.'/edit');
        self::assertSame('RA-1', $crawler->filter('#custom-field-test-fields-code')->attr('value'));
        self::assertNull($crawler->filter('#custom-field-test-fields-code')->attr('required'), 'Only a new deal is asked for it.');
        $form = $crawler->filter('#deal-form')->form(['custom_fields[test_fields_size]' => 'large']);
        $this->client->submit($form);

        self::assertResponseRedirects('/sales/deals/'.$deal);
        self::assertSame('large', $this->fields->value(DealExtension::RECORD, $deal, 'test_fields_size'));
        self::assertSame(['test_fields_size'], $this->updatedKeys($deal));

        $crawler = $this->client->submit($form, ['custom_fields[test_fields_seats]' => '2.5']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('This value cannot be saved.', trim($crawler->filter('#custom-field-test-fields-seats-error')->text()), 'A whole number is refused by the write and shown on its field.');
    }

    public function testTheWritesAskForTheRequiredFieldOnlyOfANewDeal(): void
    {
        $this->actAsOwner();
        $writes = self::getContainer()->get(DealWrites::class);
        try {
            $writes->create(new CreateDealInput('Roof audit', $this->company->id(), $this->stage->id()), customFields: []);
            self::fail('A new deal needs its required field.');
        } catch (FieldRefused $refused) {
            self::assertSame(['test_fields_code', FieldRefused::REQUIRED], [$refused->key, $refused->translationKey]);
        }

        $deal = $writes->create(new CreateDealInput('Roof audit', $this->company->id(), $this->stage->id()), customFields: ['test_fields_code' => 'RA-1']);
        $writes->update(new UpdateDealInput($deal->id()), customFields: ['test_fields_code' => null]);

        self::assertNull($this->fields->value(DealExtension::RECORD, $deal->id(), 'test_fields_code'), 'A change may clear it.');
    }

    public function testTheSideColumnShowsTheValues(): void
    {
        $deal = $this->created('Roof audit');
        $this->fields->write(DealExtension::RECORD, $deal, ['test_fields_size' => 'large', 'test_fields_flag' => true]);
        $this->em()->flush();

        $crawler = $this->client->request('GET', '/sales/deals/'.$deal);

        self::assertResponseIsSuccessful();
        $aside = $crawler->filter('[data-deal-details] [data-custom-fields-section]');
        self::assertStringContainsString('Additional details', $aside->filter('h3')->text());
        $rows = [];
        foreach ($aside->filter('dt') as $index => $term) {
            $rows[trim($term->textContent)] = trim($aside->filter('dd')->eq($index)->text());
        }
        self::assertSame('RA-1', $rows['test.field.code']);
        self::assertSame('test.size.large', $rows['test.field.size']);
        self::assertSame('Yes', $rows['test.field.flag']);
    }

    /**
     * A deal of the company made through its dialog, with its required code.
     */
    private function created(string $title): string
    {
        $form = $this->dialog('/sales/deals/new?client='.$this->company->id())->filter('#deal-form')->form(['title' => $title, 'custom_fields[test_fields_code]' => 'RA-1']);
        $this->client->submit($form);
        self::assertResponseRedirects();

        return $this->deal($title);
    }

    private function deal(string $title): string
    {
        $this->em()->clear();
        $deal = $this->em()->getRepository(Deal::class)->findOneBy(['title' => $title]);
        self::assertInstanceOf(Deal::class, $deal);

        return $deal->id();
    }

    private function dialog(string $url): Crawler
    {
        $this->client->request('GET', $url, server: [self::DIALOG => '1']);
        self::assertResponseIsSuccessful();

        return new Crawler((string) $this->client->getResponse()->getContent(), 'http://localhost'.$url);
    }

    /**
     * @return list<mixed> the custom fields the last sales.deal.updated of the deal names
     */
    private function updatedKeys(string $id): array
    {
        $data = $this->em()->getConnection()->fetchOne(
            'SELECT '.EventLogTable::DATA.' FROM '.EventLogTable::LOG.' WHERE '.EventLogTable::NAME.' = ? AND '.EventLogTable::SUBJECT_ID.' = ? ORDER BY '.EventLogTable::ID.' DESC',
            ['sales.deal.updated', $id],
        );
        self::assertIsString($data);
        $payload = json_decode($data, true);
        self::assertIsArray($payload);
        self::assertIsArray($payload['custom_fields']);

        return array_values($payload['custom_fields']);
    }
}
