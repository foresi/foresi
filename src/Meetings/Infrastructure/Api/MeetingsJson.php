<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Infrastructure\Api;

use Foresi\Meetings\Application\MeetingView;
use Foresi\Meetings\Application\SeriesView;
use Foresi\Meetings\Domain\Meeting;
use Foresi\Shared\Infrastructure\Api\JsonValues;

/**
 * A series and a meeting as REST and MCP write them (schemas MeetingSeries and Meeting).
 */
final class MeetingsJson
{
    /**
     * @return array<string, mixed>
     */
    public static function series(SeriesView $view): array
    {
        $series = $view->series;

        return [
            'id' => $series->id(),
            'name' => $series->name(),
            'visibility' => $series->visibility()->value,
            'agenda_template' => $series->agendaTemplate(),
            'default_project_id' => $series->defaultProjectId(),
            'members' => $view->members,
            'meetings' => $view->meetings,
            'last_held_on' => JsonValues::date($view->lastHeldOn),
            'archived' => $series->isArchived(),
            'archived_at' => JsonValues::timestamp($series->archivedAt()),
            'created_at' => JsonValues::timestamp($series->createdAt()),
        ];
    }

    /**
     * A meeting with its minutes, as foresi_get_meeting returns it.
     *
     * @return array<string, mixed>
     */
    public static function meeting(MeetingView $view): array
    {
        return self::row($view) + ['minutes' => $view->meeting->minutes()];
    }

    /**
     * A meeting without its minutes, as a search lists it.
     *
     * @return array<string, mixed>
     */
    public static function row(MeetingView $view): array
    {
        $meeting = $view->meeting;
        $series = $meeting->series();

        return [
            'id' => $meeting->id(),
            'title' => $meeting->title(),
            'series' => $series instanceof \Foresi\Meetings\Domain\MeetingSeries ? ['id' => $series->id(), 'name' => $series->name()] : null,
            'held_on' => JsonValues::date($meeting->heldOn()),
            'starts_at' => $meeting->startsAt(),
            'attendees' => $view->attendees,
            'links' => array_map(static fn (array $link): array => ['kind' => $link['kind'], 'id' => $link['id'], 'label' => $link['label']], $view->links),
            'version' => $meeting->version(),
            'trashed' => $meeting->isTrashed(),
            'deleted_at' => JsonValues::timestamp($meeting->deletedAt()),
            'created_at' => JsonValues::timestamp($meeting->createdAt()),
        ];
    }

    public static function summary(Meeting $meeting): string
    {
        return \sprintf('%s (%s)', $meeting->title(), $meeting->heldOn()->format('Y-m-d'));
    }
}
