<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;
use Foresi\Shared\Domain\Id;

/**
 * Meetings that recur, such as "Production weekly": its name, who sees it, its
 * agenda template, copied into the minutes of each new meeting, and the project a
 * task from its minutes is offered first. Its people are rows of their own
 * (SeriesMember). An archived series takes no new meetings; its meetings stay.
 */
#[ORM\Entity]
#[ORM\Table(name: 'meetings_series')]
#[ORM\UniqueConstraint(name: 'uniq_meetings_series_name', columns: ['name'])]
class MeetingSeries implements Audited
{
    public const int MAX_NAME = 120;
    public const int MAX_TEMPLATE = 20000;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'archived_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    public function __construct(#[ORM\Column(type: 'string', length: self::MAX_NAME)]
        private string $name, #[ORM\Column(type: 'string', length: 16, enumType: SeriesVisibility::class)]
        private SeriesVisibility $visibility, #[ORM\Column(name: 'agenda_template', type: 'text')]
        private string $agendaTemplate, #[ORM\Column(name: 'default_project_id', type: 'guid', nullable: true)]
        private ?string $defaultProjectId, #[ORM\Column(name: 'created_by', type: 'guid')]
        private string $createdBy, #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private \DateTimeImmutable $createdAt)
    {
        $this->id = Id::generate();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function visibility(): SeriesVisibility
    {
        return $this->visibility;
    }

    public function agendaTemplate(): string
    {
        return $this->agendaTemplate;
    }

    public function defaultProjectId(): ?string
    {
        return $this->defaultProjectId;
    }

    public function archivedAt(): ?\DateTimeImmutable
    {
        return $this->archivedAt;
    }

    public function isArchived(): bool
    {
        return $this->archivedAt instanceof \DateTimeImmutable;
    }

    public function createdBy(): string
    {
        return $this->createdBy;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function change(string $name, SeriesVisibility $visibility, string $agendaTemplate, ?string $defaultProjectId): void
    {
        $this->name = $name;
        $this->visibility = $visibility;
        $this->agendaTemplate = $agendaTemplate;
        $this->defaultProjectId = $defaultProjectId;
    }

    /**
     * @return bool whether it was not archived before
     */
    public function archive(\DateTimeImmutable $now): bool
    {
        if ($this->archivedAt instanceof \DateTimeImmutable) {
            return false;
        }
        $this->archivedAt = $now;

        return true;
    }
}
