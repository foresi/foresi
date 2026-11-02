<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Domain;

/**
 * How the connection to a mail server is encrypted. There is no unencrypted mode.
 */
enum MailEncryption: string
{
    /** A plain connection that must be upgraded with STARTTLS before anything is sent, usually on port 587. */
    case Starttls = 'starttls';
    /** TLS from the first byte (SMTPS), usually on port 465. */
    case Tls = 'tls';
}
