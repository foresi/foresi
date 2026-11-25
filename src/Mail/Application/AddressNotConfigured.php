<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Application;

/**
 * The server has no usable inbound address template, so no address can be built.
 */
final class AddressNotConfigured extends \DomainException
{
    public function __construct()
    {
        parent::__construct('The inbound address template is not set up.');
    }
}
