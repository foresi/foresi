<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

/**
 * No inbound message has the id.
 */
final class UnknownInboundMessage extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('No such inbound message.');
    }
}
