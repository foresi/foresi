<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

/**
 * Why no inbound address was handed out.
 */
enum AddressRefusal: string
{
    /** The server has no address template (BANK_INBOX_ADDRESS). */
    case NotConfigured = 'not_configured';
    /** No key seals the token (MAIL_SECRET_KEYS). */
    case NoKey = 'no_key';
    /** An archived account takes no payments. */
    case Archived = 'archived';
}
