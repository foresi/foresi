<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\Field;

/**
 * A value of a custom field that a write refuses (CustomFieldWrites): a key the record
 * has no field of, a field hidden from a guest, a required field left empty on
 * create, or a value its kind does not take. It names the field's key and the
 * translation key of what a person reads, so a form shows it on the field.
 */
final class FieldRefused extends \DomainException
{
    public const string UNKNOWN = 'ui.custom_fields.error.unknown';
    public const string HIDDEN = 'ui.custom_fields.error.hidden';
    public const string REQUIRED = 'ui.custom_fields.error.required';
    public const string INVALID = 'ui.custom_fields.error.invalid';

    /**
     * @param string $key            the key of the field
     * @param string $translationKey one of the constants
     */
    public function __construct(public readonly string $key, public readonly string $translationKey, string $message = '', ?\Throwable $previous = null)
    {
        parent::__construct('' === $message ? \sprintf('The custom field "%s" is refused (%s).', $key, $translationKey) : $message, 0, $previous);
    }
}
