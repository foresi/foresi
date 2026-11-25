<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Infrastructure\Field\Invalid;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Tests\Shared\Fixtures\Field\FixtureNote;
use Foresi\Tests\Shared\Fixtures\Field\FixtureNoteExtension;

/**
 * An extension DoctrineFieldSource refuses, mapped only by DoctrineFieldSourceRulesTest.
 */
#[ORM\Entity]
#[ORM\Table(name: 'invalid_withassociation')]
class WithAssociation implements FixtureNoteExtension
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string|int $id;

    #[ORM\ManyToOne(targetEntity: FixtureNote::class)]
    private ?FixtureNote $note = null;

    public function noteId(): string
    {
        return (string) $this->id;
    }
}
