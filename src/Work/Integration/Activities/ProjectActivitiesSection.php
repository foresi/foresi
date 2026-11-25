<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Integration\Activities;

use Foresi\Activities\Contract\RecordActivities;
use Foresi\Identity\Contract\StaffCheck;
use Foresi\Ui\Contract\Slot\SlotItem;
use Foresi\Ui\Contract\Slot\SlotProvider;
use Foresi\Work\Contract\ProjectSlots;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The Activities section of a project's page: the project's timeline from
 * RecordActivities, loaded by a lazy frame, whose dialogs come back to the
 * project, as the deal's page shows its activities. Activities is the
 * staff's, so a guest a project is shared with gets no such tab.
 */
final readonly class ProjectActivitiesSection implements SlotProvider
{
    public const string KEY = 'activities';

    /** The first section after the task views: what happened on the project. */
    private const int WEIGHT = 10;

    public function __construct(private UrlGeneratorInterface $urls, private TranslatorInterface $translator, private StaffCheck $staff)
    {
    }

    public function items(string $slot, array $context): iterable
    {
        $projectId = $context['projectId'] ?? null;
        $return = $context['return'] ?? null;
        if (ProjectSlots::SECTIONS !== $slot || !\is_string($projectId) || !$this->staff->isStaff()) {
            return [];
        }
        $parameters = ['kind' => ProjectActivityTarget::KIND, 'id' => $projectId];
        if (\is_string($return)) {
            $parameters['return'] = $return;
        }

        return [new SlotItem(data: [
            'key' => self::KEY,
            'label' => $this->translator->trans('work.project.tab.activities'),
            'href' => $this->urls->generate(RecordActivities::ROUTE, $parameters),
        ], weight: self::WEIGHT)];
    }
}
