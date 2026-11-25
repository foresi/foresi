<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application\Mail;

use Foresi\Shared\Application\RefusalKind;
use Foresi\Shared\Application\Refused;

/**
 * The person has no active capture address with this id; the address of
 * somebody else is not told from one that does not exist.
 */
final class UnknownCaptureAddress extends \RuntimeException implements Refused
{
    public function __construct()
    {
        parent::__construct('You have no capture address with this id.');
    }

    public function reason(): string
    {
        return 'not_found';
    }

    public function kind(): RefusalKind
    {
        return RefusalKind::NotFound;
    }

    public function field(): ?string
    {
        return null;
    }

    public function details(): array
    {
        return [];
    }
}
