<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Contract\Field;

/**
 * Where a query reads a custom field: the entity that holds it under an alias, how
 * that row joins the record's row, and the DQL expression of its value, such as
 * "cf.industry" of "Acme\Fleet\Domain\ContactExtra cf" where "cf.id = p.id".
 */
final readonly class FieldExpression
{
    /**
     * @param string $entity      the class of the entity that holds the field
     * @param string $alias       the alias of that entity
     * @param string $correlation the DQL condition that joins its row to the record's row
     * @param string $column      the DQL expression of the value
     */
    public function __construct(
        public string $entity,
        public string $alias,
        public string $correlation,
        public string $column,
    ) {
    }

    /**
     * The row as a correlated "<entity> <alias> WHERE <correlation>", which a filter
     * condition and a subquery read in.
     */
    public function within(): string
    {
        return $this->entity.' '.$this->alias.' WHERE '.$this->correlation;
    }

    /**
     * The row as a "LEFT JOIN <entity> <alias> WITH <correlation>", which a sort needs:
     * DQL orders by no subquery.
     */
    public function join(): string
    {
        return 'LEFT JOIN '.$this->entity.' '.$this->alias.' WITH '.$this->correlation;
    }
}
