<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\Field;

use Foresi\Shared\Contract\Field\RecordExtension;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A record kind that takes custom fields (ADR 0045), as its module declares it: the
 * entity of the record, the extension interface other modules implement, and the
 * lists of the record, whose rows show the fields.
 */
#[AutoconfigureTag(self::TAG)]
interface FieldRecord
{
    public const string TAG = 'app.field_record';

    /**
     * The record kind, such as ContactExtension::RECORD.
     */
    public function record(): string;

    /**
     * The scope an API token needs to read the record, such as "contacts:read", so
     * it may read the definitions of its fields too.
     */
    public function scope(): string;

    /**
     * @return class-string the entity of the record
     */
    public function entity(): string;

    /**
     * @return class-string<RecordExtension> the interface an extension of the record implements
     */
    public function extension(): string;

    /**
     * @return array<string, string> the alias of the record's row in the query of each list of the record, by list key
     */
    public function lists(): array;

    /**
     * @return list<string> the lists whose query sorts by the expression of an attribute, so a sortable field sorts them
     */
    public function sorted(): array;

    /**
     * The id of the record of a row of one of its lists.
     */
    public function id(mixed $row): string;
}
