<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Domain;

/**
 * Why a capture address refuses a change. The reason is stable: the API
 * returns it.
 */
final class CaptureAddressRefused extends \DomainException
{
    public const string LABEL_REQUIRED = 'label_required';
    public const string LABEL_TOO_LONG = 'label_too_long';

    public function __construct(public readonly string $reason)
    {
        parent::__construct(match ($reason) {
            self::LABEL_REQUIRED => 'A capture address needs a name.',
            self::LABEL_TOO_LONG => 'The name of a capture address is at most 80 characters.',
            default => 'The capture address refuses the change.',
        });
    }
}
