<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A data source another list may relate to (spec DS6): the row its attributes' expressions read,
 * as "<entity> <alias>", so a relation to one of its records reads them in that row, correlated
 * with what the relating record points to, and the relating module never names the entity.
 */
#[AutoconfigureTag(self::ROOT_TAG)]
interface RelationTarget
{
    public const string ROOT_TAG = 'foresi.ui.relation_target';

    /**
     * The row of the list, such as "Foresi\Party\Domain\Party p"; null for a list the source does not fill.
     */
    public function root(string $list): ?string;
}
