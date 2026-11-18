<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\View;

/**
 * One condition of a filter: a field, an operator and a value whose shape the
 * field's type and the operator decide. ViewFilter::parse() checks it.
 */
final readonly class Condition
{
    public function __construct(
        public string $field,
        public string $op,
        public mixed $value = null,
    ) {
    }

    /**
     * @return array{field: string, op: string, value?: mixed}
     */
    public function toArray(): array
    {
        return ['field' => $this->field, 'op' => $this->op] + (null === $this->value ? [] : ['value' => $this->value]);
    }
}
