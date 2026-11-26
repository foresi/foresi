<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Application\Field;

use Foresi\Shared\Application\Field\FieldReferenceNames;
use Foresi\Shared\Application\Field\FieldRefused;
use Foresi\Shared\Application\Field\ReferenceNames;
use Foresi\Shared\Contract\Field\FieldKind;
use PHPUnit\Framework\TestCase;

/**
 * A refused value of a custom field names its field and what is wrong, in its own
 * words or in those of the refusal it comes from; and the filter source of a reference
 * names its kind, and back.
 */
final class FieldRefusedTest extends TestCase
{
    public function testARefusalNamesItsFieldAndWhatIsWrong(): void
    {
        $refused = new FieldRefused('test_fields_code', FieldRefused::REQUIRED);
        $previous = new \InvalidArgumentException('Too long.');
        $wrapped = new FieldRefused('test_fields_code', FieldRefused::INVALID, 'Too long.', $previous);

        self::assertSame(['The custom field "test_fields_code" is refused (ui.custom_fields.error.required).', 0], [$refused->getMessage(), $refused->getCode()]);
        self::assertSame(['Too long.', 0, $previous], [$wrapped->getMessage(), $wrapped->getCode(), $wrapped->getPrevious()]);
    }

    public function testTheSourceOfAReferenceNamesItsKindAndBack(): void
    {
        self::assertSame([FieldKind::Contact, FieldKind::User, null], [
            ReferenceNames::kindOfSource(FieldReferenceNames::CONTACT_SOURCE), ReferenceNames::kindOfSource(FieldReferenceNames::USER_SOURCE), ReferenceNames::kindOfSource('custom_field.other'),
        ]);
        self::assertSame([FieldReferenceNames::CONTACT_SOURCE, FieldReferenceNames::USER_SOURCE, null], [
            ReferenceNames::sourceOf(FieldKind::Contact), ReferenceNames::sourceOf(FieldKind::User), ReferenceNames::sourceOf(FieldKind::Text),
        ]);
    }
}
