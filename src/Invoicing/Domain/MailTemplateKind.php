<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Domain;

/**
 * What a mail template is for: one template per kind of document, and later per
 * reminder or thank-you message.
 */
enum MailTemplateKind: string
{
    case Invoice = 'invoice';
}
