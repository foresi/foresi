<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Party\Integration\Documents;

use Foresi\Documents\Application\DocumentVariables;
use Foresi\Documents\Application\TemplateRenderer;
use Foresi\Documents\Contract\RecordLink;
use Foresi\Documents\Contract\VariableDefinition;
use Foresi\Party\Contract\Field\ContactExtension;
use Foresi\Shared\Contract\Field\Fields;
use Foresi\Tests\Party\Application\Query\ContactTestCase;
use Foresi\Tests\Shared\Fixtures\Field\LentFields;

/**
 * The custom fields of a contact in a document template, as {{ client.custom.<key> }}:
 * listed with the client's variables, shown as the contact's detail shows them in
 * the language of the person who generates the document, and empty without a value.
 */
final class ClientCustomFieldVariablesTest extends ContactTestCase
{
    private Fields $fields;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fields = LentFields::lend(self::getContainer(), ContactExtension::RECORD);
    }

    public function testTheFieldsAreVariablesOfTheClientBelowCustom(): void
    {
        $definitions = self::getContainer()->get(DocumentVariables::class)->definitions()['client'];
        $names = array_map(static fn (VariableDefinition $definition): string => $definition->name, $definitions);

        self::assertSame(['name', 'address_lines', 'registration_number', 'vat_id', 'persons'], \array_slice($names, 0, 5));
        self::assertContains('custom.test_fields_size', $names);
        self::assertCount(5 + 13, $names, 'every field of the fixture allows templates');
        $size = array_values(array_filter($definitions, static fn (VariableDefinition $definition): bool => 'custom.test_fields_size' === $definition->name))[0];
        self::assertSame('test.field.size', $size->label);
        self::assertSame('test.size.small', $size->example, 'the first option, by its label');
    }

    public function testTheValuesAreTheTextsOfTheDetailInTheLanguageOfTheReader(): void
    {
        $client = $this->contact('Alpha Example s.r.o.', vatId: 'CZ12345679');
        $other = $this->contact('Beta Example s.r.o.', number: '87654321', vatId: 'CZ87654321');
        $this->fields->write(ContactExtension::RECORD, $client->id(), [
            'test_fields_code' => 'A-1',
            'test_fields_seats' => 1250,
            'test_fields_due' => new \DateTimeImmutable('2027-01-31'),
            'test_fields_flag' => true,
            'test_fields_size' => 'large',
            'test_fields_tags' => ['red', 'blue'],
            'test_fields_contact' => $other->id(),
        ]);
        $this->em->flush();

        $values = self::getContainer()->get(DocumentVariables::class)->values([new RecordLink('contact', $client->id())]);
        $custom = $values['client']['custom'] ?? null;

        self::assertIsArray($custom);
        self::assertSame('A-1', $custom['test_fields_code']);
        self::assertSame("1\u{a0}250", $custom['test_fields_seats']);
        self::assertSame("31.\u{a0}1.\u{a0}2027", $custom['test_fields_due']);
        self::assertSame('Ano', $custom['test_fields_flag']);
        self::assertSame('test.size.large', $custom['test_fields_size']);
        self::assertSame('test.tag.red, test.tag.blue', $custom['test_fields_tags']);
        self::assertSame('Beta Example s.r.o.', $custom['test_fields_contact']);
        self::assertSame('', $custom['test_fields_owner'], 'a field without a value is empty, never missing');
    }

    public function testATemplateReadsTheFieldsAndItsPreviewShowsTheExamples(): void
    {
        $client = $this->contact('Alpha Example s.r.o.', vatId: 'CZ12345679');
        $this->fields->write(ContactExtension::RECORD, $client->id(), ['test_fields_size' => 'small']);
        $this->em->flush();
        $variables = self::getContainer()->get(DocumentVariables::class);
        $renderer = self::getContainer()->get(TemplateRenderer::class);
        $body = '<p>{{ client.name }}: {{ client.custom.test_fields_size }} {{ client.custom.test_fields_code }}</p>';

        self::assertSame('<p>Alpha Example s.r.o.: test.size.small </p>', $renderer->render($body, $variables->printable($variables->values([new RecordLink('contact', $client->id())]))));
        self::assertSame('<p>Example Client s.r.o.: test.size.small Example text</p>', $renderer->render($body, $variables->printable($variables->examples(), preview: true)));
        self::assertContains('client.custom.test_fields_size', $renderer->variables($body));
    }

    public function testEachKindHasAnExampleAsTheDocumentPrintsIt(): void
    {
        $examples = self::getContainer()->get(DocumentVariables::class)->examples()['client']['custom'] ?? null;

        self::assertSame([
            'test_fields_code' => 'Example text',
            'test_fields_long_note' => 'Example text',
            'test_fields_seats' => "1\u{a0}250",
            'test_fields_weight' => "1\u{a0}250",
            'test_fields_ratio' => "1\u{a0}250",
            'test_fields_due' => "26.\u{a0}11.\u{a0}2026",
            'test_fields_seen_at' => '26. 11. 2026 14:30',
            'test_fields_flag' => 'Ano',
            'test_fields_site' => 'https://example.com',
            'test_fields_size' => 'test.size.small',
            'test_fields_tags' => 'test.tag.red, test.tag.blue',
            'test_fields_contact' => 'Example Client s.r.o.',
            'test_fields_owner' => 'Jana Example',
        ], $examples);
    }

    public function testAFieldLeftOutOfTemplatesIsNoVariableAndHasNoValue(): void
    {
        $this->fields = LentFields::lend(self::getContainer(), ContactExtension::RECORD, offTemplate: ['test_fields_code']);
        $client = $this->contact('Alpha Example s.r.o.', vatId: 'CZ12345679');
        $this->fields->write(ContactExtension::RECORD, $client->id(), ['test_fields_code' => 'A-1', 'test_fields_contact' => '0192f1a4-0000-7000-8000-000000000000']);
        $this->em->flush();
        $variables = self::getContainer()->get(DocumentVariables::class);

        self::assertNotContains('custom.test_fields_code', array_map(static fn (VariableDefinition $definition): string => $definition->name, $variables->definitions()['client']));
        $custom = $variables->values([new RecordLink('contact', $client->id())])['client']['custom'] ?? null;
        self::assertIsArray($custom);
        self::assertArrayNotHasKey('test_fields_code', $custom);
        self::assertSame('Neznámé', $custom['test_fields_contact'], 'a contact that is gone');
    }

    public function testARecordWithoutFieldsHasNoCustomVariables(): void
    {
        LentFields::lend(self::getContainer(), 'another_record');
        $client = $this->contact('Alpha Example s.r.o.', vatId: 'CZ12345679');

        $values = self::getContainer()->get(DocumentVariables::class)->values([new RecordLink('contact', $client->id())])['client'] ?? [];

        self::assertArrayNotHasKey('custom', $values);
    }
}
