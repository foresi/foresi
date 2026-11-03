<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Party\Domain\EmailPurpose;

/**
 * One address the send dialog offers: an invoicing address of the contact for the
 * firm, or the address the document copied when the contact has none.
 */
final readonly class SendRecipient
{
    public const string TO = 'to';
    public const string CC = 'cc';

    /**
     * @param EmailPurpose|null $purpose null for the address on the document
     * @param string            $field   where the mail puts it, TO or CC
     */
    public function __construct(
        public string $address,
        public ?EmailPurpose $purpose,
        public string $field,
        public bool $checked,
    ) {
    }
}
