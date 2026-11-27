<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Ui\Component;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Contacts\ContactsBundle;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Shared\Application\Field\ReferenceNames;
use Foresi\Shared\Application\Field\VisibleFields;
use Foresi\Shared\Contract\Field\Fields;
use Foresi\Tests\Shared\Fixtures\Field\LentFields;
use Foresi\Tests\Shared\Fixtures\Field\StoredFields;
use Foresi\Tests\Shared\Support\FixedActor;
use Foresi\Ui\Component\CustomFields;
use Foresi\Ui\Field\CustomFieldForm;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The custom fields of a record as Ui:CustomFields shows them: on a detail each value as
 * a person reads it, by its kind, and nothing for a value its kind does not take; in a
 * form each input with what it starts with.
 */
final class CustomFieldsTest extends KernelTestCase
{
    private const string RECORD = 'lent_record';

    private EntityManagerInterface $em;
    private TranslatorInterface $translator;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $translator = self::getContainer()->get('translator');
        $translator->setLocale('en');
        $this->translator = $translator;
    }

    public function testADetailShowsEachValueAsAPersonReadsIt(): void
    {
        if (!class_exists(ContactsBundle::class)) {
            self::markTestSkipped('A contact links to its page in the contacts module, which is not installed.');
        }
        $contact = $this->contact('Example Haulage');
        $due = new \DateTimeImmutable('2027-01-31');
        $seen = new \DateTimeImmutable('2026-11-25 10:30:00');
        $fields = $this->stored(['n-1' => [
            'test_fields_code' => 12, 'test_fields_long_note' => 'First line', 'test_fields_seats' => '1234.5', 'test_fields_due' => $due, 'test_fields_seen_at' => $seen,
            'test_fields_flag' => true, 'test_fields_site' => 'https://example.com/a', 'test_fields_size' => 'small', 'test_fields_tags' => ['red', 'violet'],
            'test_fields_contact' => $contact, 'test_fields_owner' => 'nobody',
        ]]);

        $values = array_column($this->detail($fields, 'n-1')->items(), 'value');

        $unknown = $this->translator->trans('ui.custom_fields.unknown');
        $url = self::getContainer()->get(UrlGeneratorInterface::class)->generate('app_contact_show', ['id' => $contact]);
        self::assertSame([
            '12', 'First line', '1,234.5', null, null, $due->format($this->translator->trans('format.date')), $seen->format($this->translator->trans('format.datetime')),
            $this->translator->trans('ui.attribute.yes'), ['links' => [['text' => 'https://example.com/a', 'href' => 'https://example.com/a']]], 'test.size.small', 'test.tag.red, violet',
            ['links' => [['text' => 'Example Haulage', 'href' => $url]]], $unknown,
        ], $values);
    }

    public function testAValueItsKindDoesNotTakeShowsNothing(): void
    {
        $fields = $this->stored(['n-2' => [
            'test_fields_code' => '', 'test_fields_long_note' => ['a list'], 'test_fields_seats' => 'many', 'test_fields_due' => '2027-01-31', 'test_fields_seen_at' => '2026-11-25 10:30',
            'test_fields_site' => 5, 'test_fields_size' => 5, 'test_fields_tags' => [], 'test_fields_contact' => 5,
        ]]);

        $values = array_column($this->detail($fields, 'n-2')->items(), 'value');

        self::assertSame(array_fill(0, 13, null), $values, 'Neither an empty value nor an unset box reads "no".');
    }

    public function testAReferenceTheModuleCannotNameIsUnknown(): void
    {
        $fields = $this->stored(['n-1' => ['test_fields_contact' => $this->contact('Example Haulage')]]);

        $component = $this->component($fields, new ReferenceNames([]));
        $component->mode = CustomFields::DETAIL;
        $component->id = 'n-1';
        $component->only = 'test_fields_contact';
        $component->validate();

        self::assertSame([['label' => $this->translator->trans('test.field.contact'), 'value' => $this->translator->trans('ui.custom_fields.unknown')]], $component->items());
    }

    public function testTheInputsStartWithTheValuesGiven(): void
    {
        $component = $this->component($this->stored());
        $component->values = ['test_fields_code' => 'A-1', 'test_fields_tags' => ['red'], 'test_fields_contact' => 'c-1', 'test_fields_contact__text' => 'Example Haulage'];
        $component->validate();

        $inputs = array_column($component->inputs(), null, 'key');

        self::assertSame(['A-1', []], [$inputs['test_fields_code']['value'], $inputs['test_fields_code']['values']]);
        self::assertSame(['', ['red']], [$inputs['test_fields_tags']['value'], $inputs['test_fields_tags']['values']]);
        self::assertSame(['c-1', 'Example Haulage'], [$inputs['test_fields_contact']['value'], $inputs['test_fields_contact']['text']]);
        self::assertSame('', $inputs['test_fields_code']['text']);
    }

    public function testOnlyADetailListsItsRows(): void
    {
        $component = $this->component($this->stored());
        $component->validate();

        $this->expectException(\LogicException::class);
        $component->items();
    }

    /**
     * @param array<string, array<string, mixed>> $values
     */
    private function stored(array $values = []): StoredFields
    {
        return new StoredFields(LentFields::lend(self::getContainer(), self::RECORD)->definitions(self::RECORD), $values);
    }

    private function detail(Fields $fields, string $id): CustomFields
    {
        $component = $this->component($fields);
        $component->mode = CustomFields::DETAIL;
        $component->id = $id;
        $component->validate();

        return $component;
    }

    private function component(Fields $fields, ?ReferenceNames $names = null): CustomFields
    {
        $names ??= self::getContainer()->get(ReferenceNames::class);
        $visible = new VisibleFields($fields, FixedActor::staff());
        $component = new CustomFields($visible, $fields, new CustomFieldForm($visible, $fields, $names, $this->translator), $names, $this->translator, self::getContainer()->get(UrlGeneratorInterface::class));
        $component->record = self::RECORD;

        return $component;
    }

    private function contact(string $name): string
    {
        $contact = new Party(PartyKind::Person, $name, null, null, '');
        $this->em->persist($contact);
        $this->em->flush();

        return $contact->id();
    }
}
