<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\View;

/**
 * A field a list can be filtered by, as its module declares it: its key, its type,
 * the operators it allows (those of its type unless narrowed) and, for an enum, the
 * values it takes. How a condition on it becomes a query is the module's business:
 * a field of the list's data source (DS1) brings the DQL expression its attribute
 * declares, and DqlConditions compiles it; one without is compiled by the module.
 *
 * Aliases are older keys that still name the field, such as "role" for "roles": a
 * stored view or a link that names one keeps working and is read under the key.
 */
final readonly class Field
{
    /** @var list<string> */
    public array $operators;

    /**
     * @param list<string>|null     $operators    a subset of the type's, or null for all of them
     * @param list<string>          $options      the values of an enum
     * @param array<string, string> $optionLabels the translation key of each value of an enum, for the filter builder
     * @param string|null           $source       what a reference points to, such as "contact": the builder picks one with that picker
     * @param list<string>          $aliases      older keys that name the field too
     * @param string|null           $expression   the DQL expression a condition compares, as the data source declares it; null when the module compiles the field
     * @param string|null           $within       a correlated "<entity> <alias> WHERE <correlation>" the expression reads in, for a value of a related
     *                                            row, such as an e-mail of a contact: a condition holds when one such row matches, a negation when none does
     * @param int                   $scale        what a number's value is multiplied by to compare with its expression, such as 100 for whole units stored in minor ones
     */
    public function __construct(
        public string $key,
        public FieldType $type,
        public string $label,
        ?array $operators = null,
        public array $options = [],
        public array $optionLabels = [],
        public ?string $source = null,
        public array $aliases = [],
        public ?string $expression = null,
        public ?string $within = null,
        public int $scale = 1,
    ) {
        $all = $type->operators();
        if (null !== $operators && [] !== array_diff($operators, $all)) {
            throw new \InvalidArgumentException(\sprintf('The field "%s" names an operator its type %s lacks.', $key, $type->value));
        }
        if (\in_array($type, [FieldType::Enum, FieldType::MultiEnum], true) && [] === $options) {
            throw new \InvalidArgumentException(\sprintf('The enum field "%s" needs its values.', $key));
        }
        if (null !== $within && null === $expression) {
            throw new \InvalidArgumentException(\sprintf('The field "%s" reads a related row only through an expression.', $key));
        }
        $this->operators = $operators ?? $all;
    }
}
