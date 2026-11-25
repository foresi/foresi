<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Contract;

/**
 * No address can be issued, rotated or shown again on this server.
 */
final class AddressNotIssued extends \DomainException
{
    public function __construct(public readonly AddressUnavailable $reason)
    {
        parent::__construct(match ($reason) {
            AddressUnavailable::NotConfigured => 'The inbound address template is not set up.',
            AddressUnavailable::NoKey => 'No key seals the token of an inbound address.',
        });
    }
}
