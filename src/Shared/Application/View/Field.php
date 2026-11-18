<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\View;

/**
 * A field a list can be filtered by, as its module declares it: its key, its type,
 * the operators it allows (those of its type unless narrowed) and, for an enum, the
 * values it takes. How a condition on it becomes a query is the module's business.
 */
final readonly class Field
{
    /** @var list<string> */
    public array $operators;

    /**
     * @param list<string>|null $operators a subset of the type's, or null for all of them
     * @param list<string>      $options   the values of an enum
     */
    public function __construct(
        public string $key,
        public FieldType $type,
        public string $label,
        ?array $operators = null,
        public array $options = [],
    ) {
        $all = $type->operators();
        if (null !== $operators && [] !== array_diff($operators, $all)) {
            throw new \InvalidArgumentException(\sprintf('The field "%s" names an operator its type %s lacks.', $key, $type->value));
        }
        if (FieldType::Enum === $type && [] === $options) {
            throw new \InvalidArgumentException(\sprintf('The enum field "%s" needs its values.', $key));
        }
        $this->operators = $operators ?? $all;
    }
}
