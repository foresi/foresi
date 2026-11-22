<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Application;

use Foresi\Activities\Contract\ActivityTarget;
use Foresi\Activities\Domain\Activity;
use Foresi\Shared\Domain\Id;

/**
 * The records activities can be about, offered by other modules, and who sees
 * an activity through them: whoever sees one of its records, and its owner
 * when none of them is visible (none given, or all gone).
 */
final readonly class ActivityTargets
{
    /**
     * @param iterable<ActivityTarget> $targets
     */
    public function __construct(private iterable $targets)
    {
    }

    /**
     * Reads the links of an input and checks each: a kind some module offers,
     * and a record the person may link to. A link given twice counts once.
     *
     * @param list<mixed> $links
     *
     * @return list<RecordLink>
     *
     * @throws ActivitiesRefused invalid
     */
    public function check(array $links): array
    {
        $checked = [];
        foreach ($links as $at => $link) {
            $kind = \is_array($link) ? ($link['kind'] ?? null) : null;
            $id = \is_array($link) ? ($link['id'] ?? null) : null;
            if (!\is_string($kind) || !\is_string($id) || !Id::isValid($id)) {
                throw ActivitiesRefused::invalid('link_invalid', 'links', \sprintf('Link %d is not an object {kind, id} with a valid id.', $at + 1));
            }
            $target = $this->target($kind) ?? throw ActivitiesRefused::invalid('link_kind_unknown', 'links', \sprintf('Activities cannot link to records of kind "%s"; the kinds offered are: %s.', $kind, [] === $this->kinds() ? 'none' : implode(', ', $this->kinds())), ['kind' => $kind]);
            if (!$target->canLink($kind, $id)) {
                throw ActivitiesRefused::invalid('link_target_unknown', 'links', \sprintf('There is no %s with the id %s that you may link to.', $kind, $id), ['kind' => $kind, 'id' => $id]);
            }
            $checked[$kind.':'.$id] = new RecordLink($kind, $id);
        }

        return array_values($checked);
    }

    /**
     * Whether the person sees the activity: through any of its records they see,
     * or as its owner when they see none of them.
     *
     * @param list<RecordLink> $links
     */
    public function canSee(Activity $activity, array $links, ?string $userId): bool
    {
        foreach ($links as $link) {
            if ($this->target($link->kind)?->canSee($link->kind, $link->id) ?? false) {
                return true;
            }
        }

        return null !== $userId && $activity->ownerUserId() === $userId;
    }

    /**
     * What a link shows: the record's name and screen, or unavailable when the
     * record is gone, not the person's to see, or no module offers its kind.
     */
    public function describe(RecordLink $link): LinkView
    {
        $target = $this->target($link->kind);
        if (!$target instanceof ActivityTarget || !$target->canSee($link->kind, $link->id)) {
            return new LinkView($link->kind, $link->id, false, null, null);
        }

        return new LinkView($link->kind, $link->id, true, $target->label($link->kind, $link->id), $target->url($link->kind, $link->id));
    }

    /**
     * @return list<string>
     */
    public function kinds(): array
    {
        $kinds = [];
        foreach ($this->targets as $target) {
            array_push($kinds, ...$target->kinds());
        }
        sort($kinds);

        return array_values(array_unique($kinds));
    }

    private function target(string $kind): ?ActivityTarget
    {
        foreach ($this->targets as $target) {
            if (\in_array($kind, $target->kinds(), true)) {
                return $target;
            }
        }

        return null;
    }
}
