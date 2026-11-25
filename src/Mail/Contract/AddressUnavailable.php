<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Contract;

/**
 * Why this server issues no inbound address.
 */
enum AddressUnavailable: string
{
    /** No usable inbound address template is set. */
    case NotConfigured = 'not_configured';
    /** No key seals the token of an address shown again. */
    case NoKey = 'no_key';
}
