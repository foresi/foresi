<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Column;

use Foresi\Party\Contract\Contacts;
use Foresi\Ui\Contract\Relation;
use Foresi\Ui\Contract\RelationSource;
use Foresi\Work\Domain\Project;

/**
 * The projects of a contact, its client (spec DS6): the contact list filters by having some,
 * or one of a given name.
 */
final readonly class ProjectRelations implements RelationSource
{
    public function relations(string $list): iterable
    {
        if (Contacts::LIST !== $list) {
            return [];
        }

        return [new Relation('projects', 'work.relation.projects', Project::class.' wp WHERE wp.client = '.Contacts::ALIAS, name: 'wp.name', nameLabel: 'work.projects.column.name')];
    }
}
