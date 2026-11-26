<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Ui\Field;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Shared\Application\ActorRole;
use Foresi\Shared\Application\Field\ReferenceNames;
use Foresi\Shared\Application\Field\VisibleFields;
use Foresi\Shared\Contract\Field\Fields;
use Foresi\Shared\Domain\Id;
use Foresi\Tests\Shared\Fixtures\Field\LentFields;
use Foresi\Tests\Shared\Support\FixedActor;
use Foresi\Ui\Field\CustomFieldForm;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Translation\IdentityTranslator;

/**
 * The custom fields of a record's form: the texts its inputs start with, and a posted
 * form parsed by each field's kind, with a message for what is wrong.
 */
final class CustomFieldFormTest extends KernelTestCase
{
    private const string RECORD = 'lent_record';

    private EntityManagerInterface $em;
    private Fields $fields;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $this->fields = LentFields::lend(self::getContainer(), self::RECORD, ['test_fields_code']);
    }

    public function testTheInputsStartWithTheRecordsValues(): void
    {
        $contact = $this->contact('Example Haulage');
        $id = Id::generate();
        $this->fields->write(self::RECORD, $id, [
            'test_fields_code' => 'A-1', 'test_fields_seats' => 4, 'test_fields_due' => new \DateTimeImmutable('2027-01-31'),
            'test_fields_seen_at' => new \DateTimeImmutable('2026-11-25 10:30:00'), 'test_fields_flag' => true, 'test_fields_tags' => ['red'], 'test_fields_contact' => $contact,
        ]);
        $this->em->flush();

        $values = $this->form(FixedActor::staff())->values(self::RECORD, $id);

        self::assertSame('A-1', $values['test_fields_code']);
        self::assertSame('4', $values['test_fields_seats']);
        self::assertSame('2027-01-31', $values['test_fields_due']);
        self::assertSame('2026-11-25T10:30', $values['test_fields_seen_at']);
        self::assertSame('1', $values['test_fields_flag']);
        self::assertSame(['red'], $values['test_fields_tags']);
        self::assertSame([$contact, 'Example Haulage'], [$values['test_fields_contact'], $values['test_fields_contact__text']]);
        self::assertSame('', $values['test_fields_site']);
        self::assertSame(['test_fields_size' => ''], $this->form(FixedActor::guest())->values(self::RECORD, $id), 'A guest sees only the fields open to guests.');
    }

    public function testEachKindIsParsed(): void
    {
        $submission = $this->form(FixedActor::staff())->read(self::RECORD, $this->post([
            'test_fields_code' => ' A-1 ', 'test_fields_seats' => '1 234', 'test_fields_weight' => '12,5', 'test_fields_due' => '2027-01-31', 'test_fields_seen_at' => '2026-11-25T10:30',
            'test_fields_flag' => '1', 'test_fields_site' => 'https://example.com/a', 'test_fields_size' => 'large', 'test_fields_tags' => ['blue', 'red'], 'test_fields_long_note' => '',
        ], locale: 'cs'), true);

        self::assertTrue($submission->valid(), implode(', ', $submission->errors));
        $parsed = $submission->parsed;
        self::assertSame(['A-1', '1234', '12.5'], [$parsed['test_fields_code'], $parsed['test_fields_seats'], $parsed['test_fields_weight']]);
        self::assertEquals(new \DateTimeImmutable('2027-01-31 00:00:00'), $parsed['test_fields_due']);
        self::assertEquals(new \DateTimeImmutable('2026-11-25 10:30:00'), $parsed['test_fields_seen_at']);
        self::assertSame([true, 'https://example.com/a', 'large', ['blue', 'red']], [$parsed['test_fields_flag'], $parsed['test_fields_site'], $parsed['test_fields_size'], $parsed['test_fields_tags']]);
        self::assertNull($parsed['test_fields_long_note'], 'A blank input clears the value.');
        self::assertNull($parsed['test_fields_ratio'], 'An input not sent clears it too.');
        self::assertArrayHasKey('test_fields_contact', $parsed);
    }

    public function testWhatIsWrongHasAMessageOnItsField(): void
    {
        $submission = $this->form(FixedActor::staff())->read(self::RECORD, $this->post([
            'test_fields_seats' => 'many', 'test_fields_due' => '2027-02-30', 'test_fields_site' => 'ftp://example.com', 'test_fields_size' => 'huge', 'test_fields_tags' => ['green'],
            'test_fields_contact' => Id::generate(),
        ]), true);

        self::assertSame([
            'test_fields_code' => 'ui.custom_fields.error.required',
            'test_fields_seats' => 'ui.custom_fields.error.number',
            'test_fields_due' => 'ui.custom_fields.error.date',
            'test_fields_site' => 'ui.custom_fields.error.url',
            'test_fields_size' => 'ui.custom_fields.error.option',
            'test_fields_tags' => 'ui.custom_fields.error.option',
            'test_fields_contact' => 'ui.custom_fields.error.reference',
        ], $submission->errors);
        self::assertSame('many', $submission->values['test_fields_seats'], 'The form shows what was typed again.');
        self::assertArrayNotHasKey('test_fields_code', $this->form(FixedActor::staff())->read(self::RECORD, $this->post([]), false)->errors, 'Only a new record needs a required field.');
    }

    public function testATypedNameResolvesToTheOneRecordItMatches(): void
    {
        $haulage = $this->contact('Example Haulage');
        $this->contact('Example Builders');
        $this->contact('Example Building Supplies');
        $form = $this->form(FixedActor::staff());

        $one = $form->read(self::RECORD, $this->post([], ['test_fields_contact' => 'haulage']), false);
        $several = $form->read(self::RECORD, $this->post([], ['test_fields_contact' => 'build']), false);
        $picked = $form->read(self::RECORD, $this->post(['test_fields_contact' => $haulage], ['test_fields_contact' => 'what was typed']), false);

        self::assertSame($haulage, $one->parsed['test_fields_contact']);
        self::assertSame('Example Haulage', $one->values['test_fields_contact__text']);
        self::assertSame(['test_fields_contact' => 'ui.custom_fields.error.ambiguous'], $several->errors);
        self::assertSame([$haulage, 'Example Haulage'], [$picked->parsed['test_fields_contact'], $picked->values['test_fields_contact__text']]);
    }

    public function testAShortFormReadsOnlyTheRequiredFieldsAndAGuestOnlyItsOwn(): void
    {
        $post = $this->post(['test_fields_code' => 'A-1', 'test_fields_size' => 'small', 'test_fields_seats' => '3']);

        self::assertSame(['test_fields_code' => 'A-1'], $this->form(FixedActor::staff())->read(self::RECORD, $post, true, requiredOnly: true)->parsed);
        self::assertSame(['test_fields_size' => 'small'], $this->form(FixedActor::guest())->read(self::RECORD, $post, true)->parsed);
        self::assertSame(['test_fields_seats' => '3'], $this->form(FixedActor::staff())->read(self::RECORD, $post, false, key: 'test_fields_seats')->parsed);
    }

    private function form(ActorRole $actor): CustomFieldForm
    {
        $names = self::getContainer()->get(ReferenceNames::class);

        return new CustomFieldForm(new VisibleFields($this->fields, $actor), $this->fields, $names, new IdentityTranslator());
    }

    /**
     * @param array<string, string|list<string>> $fields
     * @param array<string, string>              $texts
     */
    private function post(array $fields, array $texts = [], string $locale = 'en'): Request
    {
        $request = Request::create('/', 'POST', [CustomFieldForm::NAME => $fields, CustomFieldForm::TEXT_NAME => $texts]);
        $request->setLocale($locale);

        return $request;
    }

    private function contact(string $name): string
    {
        $contact = new Party(PartyKind::Person, $name, null, null, '');
        $this->em->persist($contact);
        $this->em->flush();

        return $contact->id();
    }
}
