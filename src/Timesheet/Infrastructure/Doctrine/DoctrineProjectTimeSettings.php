<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Timesheet\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Timesheet\Application\ProjectTimeSettingStore;
use Foresi\Timesheet\Domain\ProjectTimeFields;
use Foresi\Timesheet\Domain\ProjectTimeSetting;
use Foresi\Work\Domain\Project;

final readonly class DoctrineProjectTimeSettings implements ProjectTimeSettingStore
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function setting(Project $project): ?ProjectTimeSetting
    {
        return $this->entityManager->getRepository(ProjectTimeSetting::class)->findOneBy(['project' => $project]);
    }

    public function add(ProjectTimeSetting $setting): void
    {
        $this->entityManager->persist($setting);
    }

    public function billsByDefault(Project $project): bool
    {
        return true === $this->entityManager->find(ProjectTimeFields::class, $project->id())?->billsByDefault();
    }
}
