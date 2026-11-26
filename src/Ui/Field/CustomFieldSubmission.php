<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Field;

/**
 * The custom fields of a posted form (CustomFieldForm::read()): what was typed, to show
 * the form again, the values parsed for the record's use case, and what is wrong.
 */
final readonly class CustomFieldSubmission
{
    /**
     * @param array<string, string|list<string>> $values what the form shows again, by field key (a reference's name under "<key>__text")
     * @param array<string, mixed>               $parsed the values as Fields::write() takes them, by field key; null clears one
     * @param array<string, string>              $errors the translated message of each field that is wrong, by field key
     */
    public function __construct(
        public array $values,
        public array $parsed,
        public array $errors,
    ) {
    }

    public function valid(): bool
    {
        return [] === $this->errors;
    }

    /**
     * The same submission with the refusal of a write shown on its field.
     */
    public function refused(string $key, string $message): self
    {
        return new self($this->values, $this->parsed, [$key => $message] + $this->errors);
    }
}
