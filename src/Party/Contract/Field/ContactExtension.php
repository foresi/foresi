<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Contract\Field;

use Foresi\Shared\Contract\Field\RecordExtension;

/**
 * An entity of another module that adds custom fields to a contact (ADR 0045): its id
 * is the id of the contact, and each of its other properties has
 * Foresi\Shared\Contract\Field\CustomField. Changes of it show in the history of
 * the contact.
 */
interface ContactExtension extends RecordExtension
{
    /** The record kind of its fields, as Foresi\Shared\Contract\Field\Fields names it. */
    public const string RECORD = 'contact';

    /**
     * The id of the contact the fields belong to.
     */
    public function contactId(): string;
}
