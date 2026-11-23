<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Audit\Domain;

/**
 * Marker for entities whose creation, changes and removal are written to the
 * audit log in the same transaction as the change itself. Fields that must
 * never be logged (secrets, hashes) carry the AuditExcluded attribute.
 *
 * Only fields and to-one associations are audited. A one-to-many collection
 * of audited items (the inverse side) may change, as each item is audited on
 * its own; changing any other to-many collection of an audited entity is
 * rejected at flush until to-many auditing exists, so a change can never go
 * unlogged.
 */
interface Audited
{
}
