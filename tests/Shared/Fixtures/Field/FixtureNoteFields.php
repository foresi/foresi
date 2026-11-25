<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Fixtures\Field;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Shared\Contract\Field\CustomField;
use Foresi\Shared\Contract\Field\FieldKind;

/**
 * Test-only custom fields of every kind on the test-only record, of the module
 * "test-fields" (FixtureModules), so their keys start with "test_fields_".
 */
#[ORM\Entity]
#[ORM\Table(name: 'test_fixture_note_fields')]
class FixtureNoteFields implements FixtureNoteExtension
{
    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    #[CustomField(FieldKind::Text, 'test.field.code', filterable: true, sortable: true, searchable: true)]
    private ?string $code = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[CustomField(FieldKind::LongText, 'test.field.note', searchable: true)]
    private ?string $longNote = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[CustomField(FieldKind::Number, 'test.field.seats', filterable: true)]
    private ?int $seats = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    #[CustomField(FieldKind::Number, 'test.field.weight')]
    private ?string $weight = null;

    #[ORM\Column(type: 'float', nullable: true)]
    #[CustomField(FieldKind::Number, 'test.field.ratio')]
    private ?float $ratio = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    #[CustomField(FieldKind::Date, 'test.field.due', filterable: true, sortable: true)]
    private ?\DateTimeImmutable $due = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[CustomField(FieldKind::DateTime, 'test.field.seen')]
    private ?\DateTimeImmutable $seenAt = null;

    #[ORM\Column(type: 'boolean', nullable: true)]
    #[CustomField(FieldKind::Boolean, 'test.field.flag', filterable: true)]
    private ?bool $flag = null;

    #[ORM\Column(type: 'string', length: 200, nullable: true)]
    #[CustomField(FieldKind::Url, 'test.field.site')]
    private ?string $site = null;

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    #[CustomField(FieldKind::Select, 'test.field.size', options: ['small' => 'test.size.small', 'large' => 'test.size.large'], filterable: true, guests: true)]
    private ?string $size = null;

    /** @var list<string>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    #[CustomField(FieldKind::MultiSelect, 'test.field.tags', options: ['red' => 'test.tag.red', 'blue' => 'test.tag.blue'], filterable: true)]
    private ?array $tags = null;

    #[ORM\Column(type: 'guid', nullable: true)]
    #[CustomField(FieldKind::Contact, 'test.field.contact', filterable: true)]
    private ?string $contact = null;

    #[ORM\Column(type: 'guid', nullable: true)]
    #[CustomField(FieldKind::User, 'test.field.owner')]
    private ?string $owner = null;

    /**
     * Foresi creates the row without it (DoctrineFieldSource::write()); a test may set one up.
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'guid')]
        private string $id,
    ) {
    }

    public function noteId(): string
    {
        return $this->id;
    }
}
