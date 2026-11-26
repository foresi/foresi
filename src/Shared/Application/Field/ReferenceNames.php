<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\Field;

use Foresi\Shared\Contract\Field\FieldKind;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The FieldReferenceNames of the installed modules, by the kind of reference each
 * names: the contacts when Party is installed, the people when Identity is.
 */
final readonly class ReferenceNames
{
    /**
     * @param iterable<FieldReferenceNames> $names
     */
    public function __construct(
        #[AutowireIterator(FieldReferenceNames::TAG)]
        private iterable $names = [],
    ) {
    }

    public function of(FieldKind $kind): ?FieldReferenceNames
    {
        foreach ($this->names as $names) {
            if ($names->kind() === $kind) {
                return $names;
            }
        }

        return null;
    }

    /**
     * The kind of a filter source of CustomFieldCatalog, such as FieldReferenceNames::CONTACT_SOURCE.
     */
    public static function kindOfSource(string $source): ?FieldKind
    {
        return match ($source) {
            FieldReferenceNames::CONTACT_SOURCE => FieldKind::Contact,
            FieldReferenceNames::USER_SOURCE => FieldKind::User,
            default => null,
        };
    }

    public static function sourceOf(FieldKind $kind): ?string
    {
        return match ($kind) {
            FieldKind::Contact => FieldReferenceNames::CONTACT_SOURCE,
            FieldKind::User => FieldReferenceNames::USER_SOURCE,
            default => null,
        };
    }
}
