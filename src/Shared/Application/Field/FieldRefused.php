<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\Field;

use Foresi\Shared\Contract\RefusalKind;
use Foresi\Shared\Contract\Refused;

/**
 * A value of a custom field that a write refuses (CustomFieldWrites, CustomFieldPayload):
 * a key the record has no field of, a field hidden from the actor, a required field
 * left empty on create, or a value its kind does not take. It names the field's key
 * and the translation key of what a person reads, so a form shows it on the field;
 * the API answers it as an invalid custom_fields.<key> with its reason.
 */
final class FieldRefused extends \DomainException implements Refused
{
    public const string UNKNOWN = 'ui.custom_fields.error.unknown';
    public const string HIDDEN = 'ui.custom_fields.error.hidden';
    public const string REQUIRED = 'ui.custom_fields.error.required';
    public const string INVALID = 'ui.custom_fields.error.invalid';

    /** The reasons over the API: a hidden field is answered as an unknown one, so its key is not given away. */
    public const string UNKNOWN_FIELD = 'unknown_field';
    public const string FIELD_REQUIRED = 'field_required';
    public const string INVALID_VALUE = 'invalid_value';

    /**
     * @param string $key            the key of the field
     * @param string $translationKey one of the constants
     */
    public function __construct(public readonly string $key, public readonly string $translationKey, string $message = '', ?\Throwable $previous = null)
    {
        parent::__construct('' === $message ? match ($translationKey) {
            self::REQUIRED => \sprintf('The custom field "%s" is required.', $key),
            self::INVALID => \sprintf('The custom field "%s" does not take this value.', $key),
            default => \sprintf('There is no custom field "%s".', $key),
        } : $message, 0, $previous);
    }

    public function reason(): string
    {
        return match ($this->translationKey) {
            self::REQUIRED => self::FIELD_REQUIRED,
            self::INVALID => self::INVALID_VALUE,
            default => self::UNKNOWN_FIELD,
        };
    }

    public function kind(): RefusalKind
    {
        return RefusalKind::Invalid;
    }

    public function field(): string
    {
        return 'customFields.'.$this->key;
    }

    public function details(): array
    {
        return [];
    }
}
