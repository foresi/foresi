<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Domain;

/**
 * How a received document came in: typed in by a person, made from an
 * uploaded file, or recorded through the API. It never changes.
 */
enum DocumentSource: string
{
    case Manual = 'manual';
    case Upload = 'upload';
    case Api = 'api';
}
