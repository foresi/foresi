<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Domain;

/**
 * Why an invoice goes to the trash is missing or longer than the document keeps.
 */
final class InvalidDeletionReason extends \DomainException
{
    private function __construct(string $message, private readonly bool $missing)
    {
        parent::__construct($message);
    }

    public static function missing(): self
    {
        return new self('Deleting a document needs a reason.', true);
    }

    public static function tooLong(): self
    {
        return new self('The reason for deleting a document is too long.', false);
    }

    public function isMissing(): bool
    {
        return $this->missing;
    }
}
