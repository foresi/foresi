<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Fixtures\Field;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Shared\Domain\Id;

/**
 * Test-only record that takes custom fields (FixtureNoteRecord), mapped in the test
 * environment only, so the fields of the tests change no list of the application.
 */
#[ORM\Entity]
#[ORM\Table(name: 'test_fixture_note')]
class FixtureNote
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    public function __construct(
        #[ORM\Column(type: 'string', length: 100)]
        private string $title,
    ) {
        $this->id = Id::generate();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function title(): string
    {
        return $this->title;
    }
}
