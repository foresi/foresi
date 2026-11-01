<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Infrastructure\Form;

use App\Party\Domain\InvoicingDefaults;
use App\Party\Domain\InvoicingEmail;
use App\Party\Domain\PartyRole;

/**
 * What came in for the scoped panels of the contact form, by scope: the id of
 * a firm, or SHARED for all firms. A panel that was not sent for a scope is
 * left alone: its key is missing. For a firm, null takes it back to shared.
 *
 * @phpstan-type Values array<string, string|list<string>>
 */
final readonly class FirmSettingsSubmission
{
    public const string SHARED = 'shared';

    /**
     * @param array<string, Values>                    $values   scope => field => value, to show again
     * @param array<string, array<string, bool>>       $own      firm id => panel => has its own values
     * @param array<string, array<string, string>>     $errors   scope => field => translation key
     * @param array<string, string>                    $active   panel => scope the owner was looking at
     * @param array<string, list<PartyRole>|null>      $roles    firm id => roles
     * @param array<string, InvoicingDefaults|null>    $defaults scope => settings; never null for SHARED
     * @param array<string, list<InvoicingEmail>|null> $emails   scope => addresses; never null for SHARED
     * @param array<string, bool>                      $archived scope => archived
     */
    public function __construct(
        public array $values,
        public array $own,
        public array $errors,
        public array $active,
        public array $roles,
        public array $defaults,
        public array $emails,
        public array $archived,
    ) {
    }

    public function valid(): bool
    {
        return [] === $this->errors;
    }
}
