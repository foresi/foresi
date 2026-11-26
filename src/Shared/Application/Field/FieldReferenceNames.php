<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\Field;

use Foresi\Shared\Contract\Field\FieldKind;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Port: the names of the records a reference field points to, such as the contacts
 * of a contact field; the module of those records answers it.
 */
#[AutoconfigureTag(self::TAG)]
interface FieldReferenceNames
{
    public const string TAG = 'app.field_reference_names';

    /**
     * The kind of reference it names: FieldKind::Contact or FieldKind::User.
     */
    public function kind(): FieldKind;

    /**
     * @param list<string> $ids
     *
     * @return array<string, string> the name of each record found, by id
     */
    public function names(array $ids): array;
}
