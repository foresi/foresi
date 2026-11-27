<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Contacts\Integration\Import;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Audit\Application\AuditCorrelation;
use Foresi\Contacts\Integration\Import\PartyImportTarget;
use Foresi\Import\Application\Target\TargetRegistry;
use Foresi\Import\Contract\BlankCells;
use Foresi\Import\Contract\FieldDefinition;
use Foresi\Import\Contract\FieldType;
use Foresi\Import\Contract\ImportTarget;
use Foresi\Import\Contract\RowError;
use Foresi\Import\Contract\WriteResult;
use Foresi\Import\Domain\DecimalSeparator;
use Foresi\Import\Domain\NormalisationOptions;
use Foresi\Import\Domain\ValueNormaliser;
use Foresi\Party\Contract\Field\ContactExtension;
use Foresi\Shared\Application\Field\CustomFieldPayload;
use Foresi\Shared\Contract\Field\Fields;
use Foresi\Shared\Domain\Id;
use Foresi\Tests\Shared\Fixtures\Field\LentFields;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The custom fields of the contacts in the import and the export: offered as fields
 * of the target without the target listing them, read by kind, written through
 * Fields, put back by an undo, and exported in the form the import reads back.
 */
final class ContactCustomFieldImportTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Fields $fields;
    private ImportTarget $target;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $this->fields = LentFields::lend(self::getContainer(), ContactExtension::RECORD, ['test_fields_code']);
        $this->target = self::getContainer()->get(TargetRegistry::class)->get(PartyImportTarget::KEY);
    }

    public function testEveryFieldIsOfferedInAGroupOfItsOwnWithATypeByItsKind(): void
    {
        $fields = $this->byKey();
        $custom = array_filter($fields, static fn (FieldDefinition $field): bool => 'custom' === $field->group);

        self::assertCount(13, $custom);
        self::assertSame([
            'custom_test_fields_code' => FieldType::Text,
            'custom_test_fields_long_note' => FieldType::Text,
            'custom_test_fields_seats' => FieldType::Decimal,
            'custom_test_fields_weight' => FieldType::Decimal,
            'custom_test_fields_ratio' => FieldType::Decimal,
            'custom_test_fields_due' => FieldType::Date,
            'custom_test_fields_seen_at' => FieldType::Text,
            'custom_test_fields_flag' => FieldType::Boolean,
            'custom_test_fields_site' => FieldType::Text,
            'custom_test_fields_size' => FieldType::Choice,
            'custom_test_fields_tags' => FieldType::Text,
            'custom_test_fields_contact' => FieldType::Uuid,
            'custom_test_fields_owner' => FieldType::Uuid,
        ], array_map(static fn (FieldDefinition $field): FieldType => $field->type, $custom));
        self::assertSame('test.field.size', $fields['custom_test_fields_size']->labelKey);
        self::assertSame(['small', 'large'], $fields['custom_test_fields_size']->choices);
        self::assertSame('small', $fields['custom_test_fields_size']->choiceNames['test size small'] ?? null, 'a label names its option');
        self::assertFalse($fields['custom_test_fields_code']->required, 'a required field does not hold up an import that updates');
        self::assertSame(['display_name'], array_keys(array_filter($fields, static fn (FieldDefinition $field): bool => $field->required)));
    }

    public function testACreateWritesEveryKindThroughFields(): void
    {
        $other = $this->create(['display_name' => 'Other Company'])->id;
        $id = $this->create($this->read([
            'display_name' => 'Example Company',
            'custom_test_fields_code' => 'A-1',
            'custom_test_fields_seats' => '12',
            'custom_test_fields_weight' => '12,50',
            'custom_test_fields_due' => '31. 1. 2027',
            'custom_test_fields_seen_at' => '2026-11-26 14:30',
            'custom_test_fields_flag' => 'ano',
            'custom_test_fields_site' => 'https://example.com',
            'custom_test_fields_size' => 'Large',
            'custom_test_fields_tags' => 'blue; RED',
            'custom_test_fields_contact' => $other,
        ]))->id;
        $this->em->flush();
        $this->em->clear();

        $values = $this->fields->values(ContactExtension::RECORD, [$id])[$id] ?? [];
        self::assertSame('A-1', $values['test_fields_code']);
        self::assertSame(12, $values['test_fields_seats']);
        self::assertIsNumeric($values['test_fields_weight']);
        self::assertSame(12.5, (float) $values['test_fields_weight'], 'a decimal comma');
        self::assertInstanceOf(\DateTimeImmutable::class, $values['test_fields_due']);
        self::assertSame('2027-01-31', $values['test_fields_due']->format('Y-m-d'));
        self::assertInstanceOf(\DateTimeImmutable::class, $values['test_fields_seen_at']);
        self::assertSame('2026-11-26 14:30', $values['test_fields_seen_at']->format('Y-m-d H:i'));
        self::assertTrue($values['test_fields_flag']);
        self::assertSame('https://example.com', $values['test_fields_site']);
        self::assertSame('large', $values['test_fields_size']);
        self::assertSame(['blue', 'red'], $values['test_fields_tags']);
        self::assertSame($other, $values['test_fields_contact']);
        self::assertArrayNotHasKey('test_fields_owner', $values);
    }

    public function testCellsTheKindDoesNotTakeAreRowErrorsOfTheirColumn(): void
    {
        $errors = $this->target->validate([
            'display_name' => 'Example Company',
            'custom_test_fields_site' => 'example.com',
            'custom_test_fields_seen_at' => 'tomorrow',
            'custom_test_fields_tags' => 'red, green',
            'custom_test_fields_contact' => Id::generate(),
            'custom_test_fields_owner' => Id::generate(),
        ]);

        self::assertSame([
            ['custom_test_fields_site', 'import.error.invalid_url'],
            ['custom_test_fields_seen_at', 'import.error.invalid_date_time'],
            ['custom_test_fields_tags', 'import.error.unknown_value'],
            ['custom_test_fields_contact', 'import.error.unknown_contact'],
            ['custom_test_fields_owner', 'import.error.unknown_user'],
        ], array_map(static fn (RowError $error): array => [$error->field, $error->messageKey], $errors));
        self::assertSame(['value' => 'green'], $errors[2]->parameters, 'the option no one knows');
        self::assertSame([], $this->target->validate(['display_name' => 'Example Company', 'custom_test_fields_seen_at' => '2026-11-26T14:30:00+01:00']));
    }

    public function testAnUpdateKeepsOrClearsBlankCellsAndAnUndoPutsTheValuesBack(): void
    {
        $id = $this->create(['display_name' => 'Example Company', 'custom_test_fields_code' => 'A-1', 'custom_test_fields_size' => 'small', 'custom_test_fields_tags' => 'red'])->id;
        $this->em->flush();

        $kept = $this->update($id, ['display_name' => 'Example Company', 'custom_test_fields_code' => 'B-2', 'custom_test_fields_size' => null], BlankCells::Keep);
        $this->em->flush();
        self::assertSame(['test_fields_code' => 'A-1'], $kept->before->data['custom_fields'] ?? null, 'only what the update wrote');
        self::assertSame(['B-2', 'small'], [$this->value($id, 'test_fields_code'), $this->value($id, 'test_fields_size')]);

        $cleared = $this->update($id, ['display_name' => 'Example Company', 'custom_test_fields_size' => null, 'custom_test_fields_tags' => null], BlankCells::Clear);
        $this->em->flush();
        self::assertNull($this->value($id, 'test_fields_size'));
        self::assertNull($this->value($id, 'test_fields_tags'));

        $this->target->revert($id, $cleared->before);
        $this->em->flush();
        self::assertSame(['small', ['red']], [$this->value($id, 'test_fields_size'), $this->value($id, 'test_fields_tags')]);
        $this->target->revert($id, $kept->before);
        $this->em->flush();
        self::assertSame('A-1', $this->value($id, 'test_fields_code'));
        self::assertSame('Example Company', $this->em->getConnection()->fetchOne('SELECT display_name FROM party WHERE id = ?', [$id]), 'the target put its own fields back too');
    }

    public function testAnUndoOfAnUpdatePutsBackADateAndAMomentToo(): void
    {
        $id = $this->create(['display_name' => 'Example Company', 'custom_test_fields_due' => new \DateTimeImmutable('2027-01-31'), 'custom_test_fields_seen_at' => '2026-11-26 14:30'])->id;
        $this->em->flush();
        $result = $this->update($id, ['display_name' => 'Example Company', 'custom_test_fields_due' => new \DateTimeImmutable('2028-02-29'), 'custom_test_fields_seen_at' => '2027-01-01 08:00'], BlankCells::Keep);
        $this->em->flush();

        $before = json_decode(json_encode($result->before->data, \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($before);
        $data = [];
        foreach ($before as $key => $value) {
            self::assertIsString($key);
            $data[$key] = $value;
        }
        $this->target->revert($id, new \Foresi\Import\Contract\RecordSnapshot($data));
        $this->em->flush();

        $due = $this->value($id, 'test_fields_due');
        $seen = $this->value($id, 'test_fields_seen_at');
        self::assertInstanceOf(\DateTimeImmutable::class, $due);
        self::assertInstanceOf(\DateTimeImmutable::class, $seen);
        self::assertSame(['2027-01-31', '2026-11-26 14:30'], [$due->format('Y-m-d'), $seen->format('Y-m-d H:i')]);
    }

    public function testTheExportWritesTheFieldsAsTheImportReadsThem(): void
    {
        $id = $this->create($this->read([
            'display_name' => 'Example Company',
            'custom_test_fields_code' => 'A-1',
            'custom_test_fields_weight' => '12,5',
            'custom_test_fields_due' => '2027-01-31',
            'custom_test_fields_seen_at' => '2026-11-26 14:30',
            'custom_test_fields_flag' => 'no',
            'custom_test_fields_size' => 'large',
            'custom_test_fields_tags' => 'red, blue',
        ]))->id;
        $this->em->flush();
        $this->em->clear();

        $row = [...$this->target->export([$id])][0];
        self::assertSame(array_keys($this->byKey()), array_keys($row), 'a column for every field, in order');
        self::assertSame('A-1', $row['custom_test_fields_code']);
        self::assertContains($row['custom_test_fields_weight'], ['12.5', '12.50'], 'a decimal point, as the database keeps it');
        self::assertSame('2027-01-31', $row['custom_test_fields_due']);
        self::assertSame('2026-11-26 14:30', $row['custom_test_fields_seen_at']);
        self::assertSame('Ne', $row['custom_test_fields_flag']);
        self::assertSame('test.size.large', $row['custom_test_fields_size'], 'the label');
        self::assertSame('test.tag.red, test.tag.blue', $row['custom_test_fields_tags']);
        self::assertSame('', $row['custom_test_fields_owner']);

        // The check of an upload detects the point of the numbers from the file.
        $values = $this->read($row, new NormalisationOptions(decimalSeparator: DecimalSeparator::Point));
        self::assertSame([], $this->target->validate($values));
        $result = $this->update($id, $values, BlankCells::Clear);
        $this->em->flush();
        $before = $result->before->data['custom_fields'] ?? null;
        self::assertIsArray($before);
        self::assertCount(13, $before, 'with Clear, every column of the export');
        foreach ($before as $key => $value) {
            self::assertIsString($key);
            self::assertEquals($value, CustomFieldPayload::encoded($this->definition($key), $this->value($id, $key)), $key.': the export imports again without a change');
        }
    }

    public function testAFieldThatIsNotImportedIsNeitherOfferedNorWrittenNorExported(): void
    {
        $this->fields = LentFields::lend(self::getContainer(), ContactExtension::RECORD, offImport: ['test_fields_ratio']);

        self::assertArrayNotHasKey('custom_test_fields_ratio', $this->byKey());
        $id = $this->create(['display_name' => 'Example Company', 'custom_test_fields_ratio' => '2', 'custom_test_fields_code' => 'A-1'])->id;
        $this->em->flush();
        self::assertNull($this->value($id, 'test_fields_ratio'));
        self::assertSame('A-1', $this->value($id, 'test_fields_code'));
        self::assertArrayNotHasKey('custom_test_fields_ratio', [...$this->target->export([$id])][0]);
    }

    public function testAnUpdateWithoutCustomFieldsKeepsTheSnapshotOfTheTarget(): void
    {
        $id = $this->create(['display_name' => 'Example Company'])->id;
        $this->em->flush();

        $result = $this->update($id, ['display_name' => 'Renamed Company', 'custom_test_fields_code' => null], BlankCells::Keep);

        self::assertArrayNotHasKey('custom_fields', $result->before->data);
        self::assertNotSame([], $result->before->data);
    }

    /**
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function cells(): iterable
    {
        yield 'a moment with seconds' => ['custom_test_fields_seen_at', '2026-11-26 14:30:15', null];
        yield 'a moment with an offset' => ['custom_test_fields_seen_at', '2026-11-26T14:30:00+01:00', null];
        yield 'a day that does not exist' => ['custom_test_fields_seen_at', '2026-02-30 10:00', 'import.error.invalid_date_time'];
        yield 'an hour that does not exist' => ['custom_test_fields_seen_at', '2026-11-26 25:00', 'import.error.invalid_date_time'];
        yield 'a moment after other text' => ['custom_test_fields_seen_at', 'on 2026-11-26 14:30', 'import.error.invalid_date_time'];
        yield 'a moment before other text' => ['custom_test_fields_seen_at', '2026-11-26 14:30 or later', 'import.error.invalid_date_time'];
        yield 'an address in capitals' => ['custom_test_fields_site', 'HTTPS://EXAMPLE.COM', null];
        yield 'an address without a host' => ['custom_test_fields_site', 'https://', 'import.error.invalid_url'];
        yield 'an address of another scheme' => ['custom_test_fields_site', 'ftp://example.com', 'import.error.invalid_url'];
        yield 'an address with a space' => ['custom_test_fields_site', 'https://example.com/a b', 'import.error.invalid_url'];
        yield 'options named twice' => ['custom_test_fields_tags', 'red, Red; ;test.tag.red', null];
    }

    #[DataProvider('cells')]
    public function testACellIsReadByItsKind(string $field, string $cell, ?string $error): void
    {
        $errors = $this->target->validate(['display_name' => 'Example Company', $field => $cell]);

        self::assertSame(null === $error ? [] : [[$field, $error, ['value' => $cell]]], array_map(static fn (RowError $found): array => [$found->field, $found->messageKey, $found->parameters], $errors));
    }

    public function testOptionsNamedTwiceAreWrittenOnce(): void
    {
        $id = $this->create(['display_name' => 'Example Company', 'custom_test_fields_tags' => 'red, Red; ;test.tag.blue'])->id;
        $this->em->flush();

        self::assertSame(['red', 'blue'], $this->value($id, 'test_fields_tags'));
    }

    /**
     * The cells of a row as the import reads them.
     *
     * @param array<string, string> $row
     *
     * @return array<string, mixed>
     */
    private function read(array $row, NormalisationOptions $options = new NormalisationOptions()): array
    {
        $normaliser = new ValueNormaliser();
        $fields = $this->byKey();
        $values = [];
        foreach ($row as $key => $text) {
            $value = $normaliser->normalise($fields[$key], $text, $options);
            self::assertNotInstanceOf(RowError::class, $value, $key);
            $values[$key] = $value;
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function create(array $values): WriteResult
    {
        return self::getContainer()->get(AuditCorrelation::class)->within('import:1', fn (): WriteResult => $this->target->create($values));
    }

    /**
     * @param array<string, mixed> $values
     */
    private function update(string $id, array $values, BlankCells $blankCells): WriteResult
    {
        return self::getContainer()->get(AuditCorrelation::class)->within('import:1', fn (): WriteResult => $this->target->update($id, $values, $blankCells));
    }

    /**
     * @phpstan-impure the value is read again after each write
     */
    private function value(string $id, string $key): mixed
    {
        return $this->fields->value(ContactExtension::RECORD, $id, $key);
    }

    private function definition(string $key): \Foresi\Shared\Contract\Field\FieldDefinition
    {
        return $this->fields->definition(ContactExtension::RECORD, $key) ?? throw new \LogicException('No such field.');
    }

    /**
     * @return array<string, FieldDefinition>
     */
    private function byKey(): array
    {
        $fields = [];
        foreach ($this->target->fields() as $field) {
            $fields[$field->key] = $field;
        }

        return $fields;
    }
}
