<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Infrastructure\Form;

use App\Party\Domain\InvoicingDefaults;

/**
 * What came in for the firm sections of the contact form: the values to show
 * again and a translation key per wrong field, both by firm id, and the parsed
 * settings of the firms that were sent. A firm that was not sent is left alone.
 */
final readonly class FirmSettingsSubmission
{
    /**
     * @param array<string, array<string, string>> $values   firm id => field => value
     * @param array<string, array<string, string>> $errors   firm id => field => translation key
     * @param array<string, InvoicingDefaults>     $settings firm id => parsed settings
     */
    public function __construct(
        public array $values,
        public array $errors,
        public array $settings,
    ) {
    }

    public function valid(): bool
    {
        return [] === $this->errors;
    }
}
