<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Contract\Field;

/**
 * An entity of a module that adds custom fields to a record of another module (ADR
 * 0045): its id is the record's id, in a table of its own, and each of its fields
 * other than the id is a property with CustomField. The record's module publishes
 * an interface that extends this one for its record, such as
 * Foresi\Party\Contract\Field\ContactExtension; the entity implements that one.
 *
 * Changes of the entity are audited under the record, so they show in its history.
 */
interface RecordExtension
{
}
