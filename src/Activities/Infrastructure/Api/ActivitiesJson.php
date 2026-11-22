<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Infrastructure\Api;

use Foresi\Activities\Application\ActivitiesStore;
use Foresi\Activities\Application\ActivityReads;
use Foresi\Activities\Application\ActivityTargets;
use Foresi\Activities\Application\ActivityTypeNames;
use Foresi\Activities\Application\RecordLink;
use Foresi\Activities\Domain\Activity;
use Foresi\Activities\Domain\ActivityType;
use Foresi\Attachment\Application\VisibleAttachments;
use Foresi\Attachment\Domain\Attachment;
use Foresi\Attachment\Infrastructure\Controller\AttachmentController;
use Foresi\Shared\Infrastructure\Api\JsonValues;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The JSON of Activities, the same over REST and MCP.
 */
final readonly class ActivitiesJson
{
    public function __construct(
        private ActivitiesStore $store,
        private ActivityReads $reads,
        private ActivityTargets $targets,
        private ActivityTypeNames $names,
        private VisibleAttachments $attachments,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function type(ActivityType $type): array
    {
        return [
            'id' => $type->id(),
            'key' => $type->key(),
            'name' => $this->names->of($type),
            'timing' => $type->timing()->value,
            'default_duration_minutes' => $type->defaultDurationMinutes(),
            'archived' => $type->isArchived(),
        ];
    }

    /**
     * The activity with its records (a record that is gone or not the person's
     * to see is available: false) and its files.
     *
     * @return array<string, mixed>
     */
    public function activity(Activity $activity): array
    {
        $type = $this->store->type($activity->typeId()) ?? throw new \LogicException('An activity has its type.');

        return [
            'id' => $activity->id(),
            'type' => ['id' => $type->id(), 'key' => $type->key(), 'name' => $this->names->of($type), 'timing' => $type->timing()->value],
            'subject' => $activity->subject(),
            'body' => $activity->body(),
            'planned_on' => JsonValues::date($activity->plannedOn()),
            'planned_at' => JsonValues::timestamp($activity->plannedAt()),
            'duration_minutes' => $activity->durationMinutes(),
            'done' => $activity->isDone(),
            'done_at' => JsonValues::timestamp($activity->doneAt()),
            'outcome' => $activity->outcome(),
            'owner_user_id' => $activity->ownerUserId(),
            'created_by' => $activity->createdBy(),
            'created_at' => JsonValues::timestamp($activity->createdAt()),
            'updated_at' => JsonValues::timestamp($activity->updatedAt()),
            'trashed' => $activity->isTrashed(),
            'links' => array_map(function (RecordLink $link): array {
                $view = $this->targets->describe($link);

                return ['kind' => $view->kind, 'id' => $view->id, 'available' => $view->available, 'label' => $view->label];
            }, $this->reads->links($activity)),
            'files' => array_map(fn (Attachment $file): array => [
                'id' => $file->id(),
                'name' => $file->fileName(),
                'media_type' => $file->mediaType(),
                'size' => $file->size(),
                'url' => $this->urls->generate(AttachmentController::ROUTE, ['id' => $file->id()], UrlGeneratorInterface::ABSOLUTE_URL),
            ], $this->attachments->of(Activity::ATTACHMENT_KIND, $activity->id())),
        ];
    }
}
