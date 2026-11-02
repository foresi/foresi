<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Domain;

/**
 * What becomes of an invoice a recurring template issued: it is sent to the
 * customer, or it waits until someone has looked at it and sends it.
 */
enum RecurringDelivery: string
{
    case Send = 'send';
    case Review = 'review';
}
