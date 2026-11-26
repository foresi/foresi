<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Import\Contract;

/**
 * An import target whose records take custom fields (ADR 0045), since module API
 * 1.9. The import adds every custom field of the record kind that allows import
 * to the target's fields and to its export, as "custom_<key>", and writes them
 * through the custom fields after the target wrote its own: the target never
 * lists them, never sees their values, and an undo puts them back by itself.
 */
interface FieldRecordTarget extends ImportTarget
{
    /**
     * The record kind of the target's records, such as ContactExtension::RECORD.
     */
    public function fieldRecord(): string;
}
