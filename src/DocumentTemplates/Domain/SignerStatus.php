<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\DocumentTemplates\Domain;

enum SignerStatus: string
{
    case Pending = 'pending';
    case Signed = 'signed';
    case Declined = 'declined';
}
