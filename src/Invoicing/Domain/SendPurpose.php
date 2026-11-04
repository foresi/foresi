<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Domain;

/**
 * Why a document was sent: the document itself, or a reminder that it is overdue.
 */
enum SendPurpose: string
{
    case Invoice = 'invoice';
    case Reminder = 'reminder';
}
