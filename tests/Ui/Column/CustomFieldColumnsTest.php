<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Ui\Column;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Identity\Domain\Role;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Shared\Contract\Field\Fields;
use Foresi\Shared\Domain\View\AttributeType;
use Foresi\Tests\Identity\Support\IdentityFixtures;
use Foresi\Tests\Shared\Fixtures\Field\FixtureNote;
use Foresi\Tests\Shared\Fixtures\Field\FixtureNoteExtension;
use Foresi\Tests\Shared\Fixtures\Field\FixtureNoteRecord;
use Foresi\Ui\Column\ListColumnRegistry;
use Foresi\Ui\Contract\Attribute;
use Foresi\Ui\Contract\Column;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A column of each custom field in the lists of its record (tests/Shared/Fixtures/Field):
 * hidden by default, after the list's own, sortable where the list sorts by columns, a
 * reference shown by its record's name, and none for a guest unless it is open to guests.
 */
final class CustomFieldColumnsTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ListColumnRegistry $registry;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        new IdentityFixtures($this->em)->rebuildSchema();
        $this->registry = self::getContainer()->get(ListColumnRegistry::class);
    }

    public function testEachFieldIsAColumnOfTheRecordsList(): void
    {
        $columns = $this->registry->columns(FixtureNoteRecord::LIST);
        $attributes = array_combine(array_map(static fn (Column $column): string => $column->key, $columns), array_map(static fn (Column $column): Attribute => $column->attribute, $columns));

        self::assertSame([
            'test_fields_code' => AttributeType::Text, 'test_fields_long_note' => AttributeType::Text, 'test_fields_seats' => AttributeType::Number, 'test_fields_weight' => AttributeType::Number,
            'test_fields_ratio' => AttributeType::Number, 'test_fields_due' => AttributeType::Date, 'test_fields_seen_at' => AttributeType::DateTime, 'test_fields_flag' => AttributeType::Boolean,
            'test_fields_site' => AttributeType::Url, 'test_fields_size' => AttributeType::Enum, 'test_fields_tags' => AttributeType::MultiEnum, 'test_fields_contact' => AttributeType::Reference,
            'test_fields_owner' => AttributeType::Reference,
        ], array_map(static fn (Attribute $attribute): AttributeType => $attribute->type, $attributes));
        self::assertSame(['test_fields_code', 'test_fields_due'], array_keys(array_filter($attributes, static fn (Attribute $attribute): bool => $attribute->sortable())));
        self::assertSame('cf_test_fields_due.due', $attributes['test_fields_due']->sort);
        self::assertSame('test.field.size', $attributes['test_fields_size']->label);
        self::assertSame(['small' => 'test.size.small', 'large' => 'test.size.large'], $attributes['test_fields_size']->options);
        self::assertSame([1000, 1012], [$attributes['test_fields_code']->defaultPosition, $attributes['test_fields_owner']->defaultPosition]);
        self::assertTrue($attributes['test_fields_long_note']->truncate);
        self::assertFalse($attributes['test_fields_code']->truncate);
        self::assertSame([], array_filter($attributes, static fn (Attribute $attribute): bool => $attribute->defaultVisible || $attribute->filterable), 'A person adds them; the filters come from the catalogue.');
        self::assertSame([], $this->registry->columns('fixture.none'));
    }

    public function testAColumnShowsTheValueOfItsRow(): void
    {
        $note = new FixtureNote('Example note');
        $other = new FixtureNote('Other note');
        $contact = new Party(PartyKind::Person, 'Example Person', null, null, '');
        $fixtures = new IdentityFixtures($this->em);
        $user = $fixtures->user();
        $fixtures->join($user, Role::Member);
        $this->em->persist($note);
        $this->em->persist($other);
        $this->em->persist($contact);
        $this->em->flush();
        $fields = self::getContainer()->get(Fields::class);
        $fields->write(FixtureNoteExtension::RECORD, $note->id(), ['test_fields_size' => 'large', 'test_fields_contact' => $contact->id(), 'test_fields_owner' => $user->id(), 'test_fields_tags' => ['red']]);
        $fields->write(FixtureNoteExtension::RECORD, $other->id(), ['test_fields_contact' => '0192f1a4-0000-7000-8000-00000000dead']);
        $this->em->flush();

        $value = fn (string $key, FixtureNote $row): mixed => $this->registry->attribute(FixtureNoteRecord::LIST, $key)->value($row);

        self::assertSame('large', $value('test_fields_size', $note));
        self::assertSame(['red'], $value('test_fields_tags', $note));
        self::assertSame('Example Person', $value('test_fields_contact', $note));
        self::assertSame(self::getContainer()->get(\Foresi\Identity\Application\People::class)->all()[0]->name, $value('test_fields_owner', $note));
        self::assertSame('0192f1a4-0000-7000-8000-00000000dead', $value('test_fields_contact', $other), 'A contact no longer there shows its id.');
        self::assertNull($value('test_fields_owner', $other));
        self::assertNull($value('test_fields_code', $other));
    }

    public function testAGuestSeesOnlyTheFieldsOpenToGuests(): void
    {
        $available = array_map(
            static fn (Column $column): string => $column->key,
            array_values(array_filter($this->registry->columns(FixtureNoteRecord::LIST), static fn (Column $column): bool => $column->available([]))),
        );

        self::assertSame(['test_fields_size'], $available, 'Nobody signed in is no staff.');
    }
}
