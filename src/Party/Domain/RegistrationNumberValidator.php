<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Domain;

/**
 * Country-specific rules for company registration numbers. Implementations
 * are registered through the container tag "app.registration_number_validator".
 */
interface RegistrationNumberValidator
{
    /**
     * @param string $countryCode ISO 3166-1 alpha-2, upper case
     */
    public function supports(string $countryCode): bool;

    public function normalize(string $raw): string;

    /**
     * @throws InvalidRegistrationNumber
     */
    public function validate(string $normalized): void;
}
