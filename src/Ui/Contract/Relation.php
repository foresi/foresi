<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Contract;

/**
 * A relation of a list's rows to other records, which the list can be filtered by (spec DS6, D4).
 * It is read in a correlated subquery over the list's row, so the list's query gains no join and
 * a negation holds when no related row matches.
 *
 * To one record, it names the list of its target and the DQL expression of the record the row
 * points to ("on", such as "i.customerParty"): every filterable attribute of the target's data
 * source is a field of the list too, keyed "<relation>.<attribute>", read in the target's row
 * (RelationTarget), so no module names another's entities (DS6). One hop: a target's relations
 * are not offered. To many records, it reads in "<entity> <alias>[, <entity> <alias>] WHERE
 * <correlation>" and is a field of whether the row has any (Airtable, HubSpot, Notion: "is not
 * empty" / "is empty"), and, given the expression of a related record's name, a text field
 * that holds when one of them is so named and its negation when none is.
 */
final readonly class Relation
{
    /**
     * @param string      $key       snake_case, the prefix of its fields' keys
     * @param string      $label     the translation key of its name, which groups its fields
     * @param string|null $within    to many: the correlated "<entity> <alias> WHERE <correlation>" the related rows are read in
     * @param string|null $target    to one: the list key of the target's data source
     * @param string|null $on        to one: the DQL expression of the target's record the row points to
     * @param string|null $name      to many: the DQL expression of a related record's name, within "within"
     * @param string      $nameLabel to many: the translation key of what the name is, such as "Number"
     */
    public function __construct(
        public string $key,
        public string $label,
        public ?string $within = null,
        public ?string $target = null,
        public ?string $on = null,
        public ?string $name = null,
        public string $nameLabel = 'ui.view_filter.name',
    ) {
        if (1 !== preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
            throw new \InvalidArgumentException(\sprintf('A relation key is a snake_case identifier such as "customer", not "%s".', $key));
        }
        if ('' === $label) {
            throw new \InvalidArgumentException(\sprintf('The relation "%s" needs a label.', $key));
        }
        $one = null !== $target && null !== $on && '' !== $on && null === $within && null === $name;
        $many = null === $target && null === $on && null !== $within && 1 === preg_match('/^\s*\S+\s+[A-Za-z_]\w*(\s*,\s*\S+\s+[A-Za-z_]\w*)*\s+WHERE\s/i', $within);
        if (!$one && !$many) {
            throw new \InvalidArgumentException(\sprintf('The relation "%s" is to one record (a target list and what it is on) or to many (read in "<entity> <alias> WHERE <correlation>", maybe named).', $key));
        }
        if ('' === $name) {
            throw new \InvalidArgumentException(\sprintf('The name of relation "%s" cannot be empty.', $key));
        }
    }

    public function many(): bool
    {
        return null !== $this->within;
    }
}
