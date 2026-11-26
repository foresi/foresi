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
 * The people a user field points to: the people of the organisation, read once a
 * request, their names and those whose name or e-mail matches a typed text. A person
 * has no page to open.
 */
final class UserFieldNames implements FieldReferenceNames, ResetInterface
{
    /** @var array<string, array{name: string, email: string}>|null by user id */
    private ?array $known = null;

    public function __construct(private readonly People $people)
    {
    }

    public function kind(): FieldKind
    {
        return FieldKind::User;
    }

    public function names(array $ids): array
    {
        return array_intersect_key(array_map(static fn (array $person): string => $person['name'], $this->people()), array_flip($ids));
    }

    public function matches(string $text, int $limit): array
    {
        $text = mb_strtolower(trim($text));
        $matches = [];
        foreach ($this->people() as $id => $person) {
            if (\count($matches) >= $limit) {
                break;
            }
            if ('' === $text || str_contains(mb_strtolower($person['name'].' '.$person['email']), $text)) {
                $matches[] = ['id' => $id, 'label' => $person['name'], 'hint' => $person['email']];
            }
        }

        return $matches;
    }

    public function url(string $id): ?string
    {
        return null;
    }

    public function reset(): void
    {
        $this->known = null;
    }

    /**
     * @return array<string, array{name: string, email: string}> by user id
     */
    private function people(): array
    {
        if (null === $this->known) {
            $this->known = [];
            foreach ($this->people->all() as $person) {
                $this->known[$person->userId] = ['name' => $person->name, 'email' => $person->email];
            }
        }

        return $this->known;
    }
}
