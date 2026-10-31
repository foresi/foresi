<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Infrastructure\Form;

use App\Party\Application\LegalEntityInput;

/**
 * What came in from the firm form: the values to show again, a message per
 * field that is wrong and, when everything is fine, the parsed input.
 */
final readonly class LegalEntitySubmission
{
    /**
     * @param array<string, string>                                      $values
     * @param array<string, array{0: string, 1?: array<string, string>}> $errors field => translation key and its parameters
     */
    public function __construct(
        public array $values,
        public array $errors,
        public ?LegalEntityInput $input,
    ) {
    }

    public function valid(): bool
    {
        return [] === $this->errors && $this->input instanceof LegalEntityInput;
    }
}
