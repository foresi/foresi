<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Timesheet\Infrastructure\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Foresi\Party\Domain\Party;
use Foresi\Timesheet\Domain\ProjectTimeFields;
use Foresi\Work\Domain\Project;

/**
 * A new project bills its time by default when it is for a client, as Harvest
 * starts a client's project billable; one without a client keeps the field
 * unset, which bills nothing by default. The fields are made when the project
 * is persisted, before its use case writes the custom fields it was created
 * with, so a value given then, false included, wins.
 */
#[AsEntityListener(event: Events::prePersist, method: 'projectPersisted', entity: Project::class)]
final readonly class ProjectTimeFieldDefaults
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function projectPersisted(Project $project): void
    {
        if ($project->client() instanceof Party && !$this->em->getUnitOfWork()->tryGetById($project->id(), ProjectTimeFields::class) instanceof ProjectTimeFields) {
            $this->em->persist(new ProjectTimeFields($project->id(), true));
        }
    }
}
