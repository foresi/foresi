<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Domain;

/**
 * Why an endpoint stopped receiving: it answered 410 Gone, it failed for five
 * days, its owner may no longer read what it subscribes to, or a person turned
 * it off.
 */
enum DisabledReason: string
{
    case Gone = 'gone';
    case Failing = 'failing';
    case OwnerLostAccess = 'owner_lost_access';
    case Manual = 'manual';
}
