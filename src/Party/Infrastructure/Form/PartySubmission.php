<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Infrastructure\Form;

use App\Party\Application\PartyInput;

/**
 * What came in from the party form: the values to show again, a translation
 * key per field that is wrong and, when everything is fine, the parsed input.
 */
final readonly class PartySubmission
{
    /**
     * @param array<string, string|list<string>> $values
     * @param array<string, string>              $errors field => translation key
     */
    public function __construct(
        public array $values,
        public array $errors,
        public ?PartyInput $input,
    ) {
    }

    public function valid(): bool
    {
        return [] === $this->errors && $this->input instanceof PartyInput;
    }
}
