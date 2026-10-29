<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Audit\Domain;

/**
 * Keeps a property of an Audited entity out of the audit log entirely: neither
 * its old nor its new value is ever written. On an embedded property it
 * excludes every column of the embeddable.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class AuditExcluded
{
}
