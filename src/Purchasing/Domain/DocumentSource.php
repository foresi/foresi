<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Domain;

/**
 * How a received document came in: typed in by a person, made from an
 * uploaded file, recorded through the API, or filed from a mail sent to a capture address. It never changes.
 */
enum DocumentSource: string
{
    case Manual = 'manual';
    case Upload = 'upload';
    case Api = 'api';
    case Mail = 'mail';
}
