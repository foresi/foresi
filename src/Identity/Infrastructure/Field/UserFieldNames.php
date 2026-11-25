<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Infrastructure\Field;

use Foresi\Identity\Application\People;
use Foresi\Shared\Application\Field\FieldReferenceNames;
use Foresi\Shared\Contract\Field\FieldKind;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The names of the people a user field points to: the people of the organisation,
 * read once a request.
 */
final class UserFieldNames implements FieldReferenceNames, ResetInterface
{
    /** @var array<string, string>|null by user id */
    private ?array $names = null;

    public function __construct(private readonly People $people)
    {
    }

    public function kind(): FieldKind
    {
        return FieldKind::User;
    }

    public function names(array $ids): array
    {
        if (null === $this->names) {
            $this->names = [];
            foreach ($this->people->all() as $person) {
                $this->names[$person->userId] = $person->name;
            }
        }

        return array_intersect_key($this->names, array_flip($ids));
    }

    public function reset(): void
    {
        $this->names = null;
    }
}
