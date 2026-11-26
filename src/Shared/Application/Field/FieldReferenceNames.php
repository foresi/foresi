<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\Field;

use Foresi\Shared\Contract\Field\FieldKind;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Port: the records a reference field points to, such as the contacts of a contact
 * field: their names, the records that match a typed text, and the page of each;
 * the module of those records answers it.
 */
#[AutoconfigureTag(self::TAG)]
interface FieldReferenceNames
{
    public const string TAG = 'app.field_reference_names';

    /**
     * The route of the picker of a reference field (Foresi\Ui\Controller\FieldReferenceController),
     * with the parameter "kind" (FieldKind::Contact or FieldKind::User as a value) and the query "q".
     */
    public const string ROUTE = 'app_field_reference_matches';

    /** The filter source of a contact field, and of a user field (CustomFieldCatalog). */
    public const string CONTACT_SOURCE = 'custom_field.contact';
    public const string USER_SOURCE = 'custom_field.user';

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

    /**
     * The records whose name matches a typed text, the best first, as a picker offers them.
     *
     * @return list<array{id: string, label: string, hint: ?string}>
     */
    public function matches(string $text, int $limit): array;

    /**
     * The page of a record, null when it has none to open.
     */
    public function url(string $id): ?string;
}
