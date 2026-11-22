<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;
use Foresi\Shared\Domain\Id;

/**
 * A kind of activity, such as a meeting or a call, and the time it has. Its
 * key is unique and never changes, so events and other modules can name it.
 * A type seeded with the module has no name of its own until someone renames
 * it: it is shown under the name its key has in the person's language.
 */
#[ORM\Entity]
#[ORM\Table(name: 'activities_type')]
#[ORM\UniqueConstraint(name: 'uniq_activities_type_key', columns: ['type_key'])]
class ActivityType implements Audited
{
    public const int MAX_NAME = 80;
    public const int MAX_DURATION = 24 * 60;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'type_key', type: 'string', length: 40)]
    private string $key;

    #[ORM\Column(type: 'string', length: self::MAX_NAME, nullable: true)]
    private ?string $name;

    #[ORM\Column(type: 'string', length: 8, enumType: ActivityTiming::class)]
    private ActivityTiming $timing;

    #[ORM\Column(name: 'default_duration_minutes', type: 'integer', nullable: true)]
    private ?int $defaultDurationMinutes;

    #[ORM\Column(name: 'archived_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    public function __construct(
        string $key,
        ?string $name,
        ActivityTiming $timing,
        ?int $defaultDurationMinutes,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private \DateTimeImmutable $createdAt,
    ) {
        if (1 !== preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key)) {
            throw new InvalidField('key', 'A type key is a lowercase identifier of up to 40 characters, such as "site_visit".');
        }
        $this->id = Id::generate();
        $this->key = $key;
        $this->change($name, $timing, $defaultDurationMinutes);
    }

    /**
     * A timed type keeps its default duration; the others have none.
     */
    public function change(?string $name, ActivityTiming $timing, ?int $defaultDurationMinutes): void
    {
        if (null !== $name) {
            $name = trim($name);
            if ('' === $name || mb_strlen($name) > self::MAX_NAME) {
                throw new InvalidField('name', \sprintf('A type name has 1 to %d characters.', self::MAX_NAME));
            }
        }
        if (null !== $defaultDurationMinutes && ($defaultDurationMinutes < 1 || $defaultDurationMinutes > self::MAX_DURATION)) {
            throw new InvalidField('defaultDurationMinutes', \sprintf('A default duration is 1 to %d minutes.', self::MAX_DURATION));
        }
        $this->name = $name;
        $this->timing = $timing;
        $this->defaultDurationMinutes = ActivityTiming::Timed === $timing ? $defaultDurationMinutes : null;
    }

    public function archive(\DateTimeImmutable $now): void
    {
        $this->archivedAt ??= $now;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function key(): string
    {
        return $this->key;
    }

    /** The name someone gave it, or null for a seeded type that keeps its translated name. */
    public function name(): ?string
    {
        return $this->name;
    }

    public function timing(): ActivityTiming
    {
        return $this->timing;
    }

    public function defaultDurationMinutes(): ?int
    {
        return $this->defaultDurationMinutes;
    }

    public function isArchived(): bool
    {
        return $this->archivedAt instanceof \DateTimeImmutable;
    }

    public function archivedAt(): ?\DateTimeImmutable
    {
        return $this->archivedAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
