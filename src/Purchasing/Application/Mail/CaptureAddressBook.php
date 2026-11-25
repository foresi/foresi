<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application\Mail;

use Foresi\Purchasing\Domain\CaptureAddress;

/**
 * The capture addresses of the people, the revoked ones included.
 */
interface CaptureAddressBook
{
    /**
     * The person's address that takes in mail; null when they have none.
     */
    public function activeOf(string $ownerUserId): ?CaptureAddress;

    /**
     * The address Mail knows by this id, revoked or not; null when it is not a capture address.
     */
    public function ofMailAddress(string $mailAddressId): ?CaptureAddress;

    public function add(CaptureAddress $address): void;
}
