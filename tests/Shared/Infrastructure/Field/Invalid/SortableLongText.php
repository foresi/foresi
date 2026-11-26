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
#[ORM\Table(name: 'invalid_sortablelongtext')]
class SortableLongText implements FixtureNoteExtension
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string|int $id;

    #[ORM\Column(type: 'text', nullable: true)]
    #[CustomField(FieldKind::LongText, 'test.label', sortable: true)]
    private ?string $value = null;

    public function noteId(): string
    {
        return (string) $this->id;
    }
}
