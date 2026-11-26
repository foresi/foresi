<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Infrastructure\Field;

use Foresi\Shared\Application\View\Field;
use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Application\View\QuickPick;
use Foresi\Shared\Infrastructure\Field\CustomFieldCatalog;
use Foresi\Tests\Shared\Fixtures\Field\FixtureNoteFields;
use Foresi\Tests\Shared\Fixtures\Field\FixtureNoteRecord;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The filter fields of the filterable custom fields of a record's lists, typed by their
 * kind and read in the extension's row (tests/Shared/Fixtures/Field).
 */
final class CustomFieldCatalogTest extends KernelTestCase
{
    public function testEachFilterableFieldIsAFilterFieldOfTheList(): void
    {
        self::bootKernel();
        $catalog = self::getContainer()->get(CustomFieldCatalog::class);

        $fields = [];
        foreach ($catalog->fields(FixtureNoteRecord::LIST) as $field) {
            $fields[$field->key] = $field;
        }

        self::assertSame([
            'test_fields_code' => FieldType::Text, 'test_fields_seats' => FieldType::Number, 'test_fields_due' => FieldType::Date, 'test_fields_flag' => FieldType::Boolean,
            'test_fields_size' => FieldType::Enum, 'test_fields_tags' => FieldType::MultiEnum, 'test_fields_contact' => FieldType::Reference,
        ], array_map(static fn (Field $field): FieldType => $field->type, $fields));
        $size = $fields['test_fields_size'];
        self::assertSame('test.field.size', $size->label);
        self::assertSame(['small', 'large'], $size->options);
        self::assertSame(['small' => 'test.size.small', 'large' => 'test.size.large'], $size->optionLabels);
        self::assertSame('cf_test_fields_size.size', $size->expression);
        self::assertSame(FixtureNoteFields::class.' cf_test_fields_size WHERE cf_test_fields_size.id = n.id', $size->within);
        self::assertSame([], $fields['test_fields_code']->options);
        self::assertSame([], $fields['test_fields_code']->optionLabels);
        self::assertSame(QuickPick::Sheet, $fields['test_fields_contact']->quickPick, 'A reference compares the id it holds.');
        self::assertSame([], $catalog->fields('fixture.none'));
    }
}
