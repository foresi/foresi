<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Timesheet\Domain;

use Foresi\Shared\Contract\Field\CustomField;
use Foresi\Shared\Contract\Field\FieldKind;
use Foresi\Timesheet\Domain\ProjectTimeFields;
use PHPUnit\Framework\TestCase;

final class ProjectTimeFieldsTest extends TestCase
{
    public function testBillableByDefaultIsAYesOrNoFieldTheListsFilterAndSortBy(): void
    {
        $attributes = new \ReflectionProperty(ProjectTimeFields::class, 'billableByDefault')->getAttributes(CustomField::class);
        self::assertCount(1, $attributes);
        $field = $attributes[0]->newInstance();

        self::assertSame([FieldKind::Boolean, 'timesheet.project.field.billable', true, true], [$field->kind, $field->label, $field->filterable, $field->sortable]);
    }
}
