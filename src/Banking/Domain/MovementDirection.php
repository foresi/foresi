<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Domain;

/**
 * Which way money moved on the firm's own account: in from a payer, or out.
 */
enum MovementDirection: string
{
    case Incoming = 'incoming';
    case Outgoing = 'outgoing';
}
