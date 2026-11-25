<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Infrastructure\Field\Invalid;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Shared\Contract\Field\CustomField;
use Foresi\Shared\Contract\Field\FieldKind;
use Foresi\Tests\Shared\Fixtures\Field\FixtureNoteExtension;

/**
 * An extension DoctrineFieldSource refuses, mapped only by DoctrineFieldSourceRulesTest.
 */
#[ORM\Entity]
#[ORM\Table(name: 'invalid_emptylabel')]
class EmptyLabel implements FixtureNoteExtension
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string|int $id;

    #[ORM\Column(type: 'string', nullable: true)]
    #[CustomField(FieldKind::Text, '')]
    private ?string $value = null;

    public function noteId(): string
    {
        return (string) $this->id;
    }
}
