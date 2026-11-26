<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Integration\Inbox;

use Foresi\Activities\Application\ActivityWrites;
use Foresi\Activities\Application\Api\CreateActivityInput;
use Foresi\Activities\Contract\LinkableRecords;
use Foresi\Activities\Infrastructure\Api\ActivitiesJson;
use Foresi\Activities\Infrastructure\Api\ActivitiesRoutes;
use Foresi\Inbox\Contract\InboxItems;
use Foresi\Inbox\Contract\InboxItemView;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * inbox.actions.log_activity: the activity activities.create makes, linked to
 * the records of the item: its contact, its company and every output whose
 * kind activities can be about (a kind they cannot, or a record the person may
 * not link, is left out).
 */
final readonly class LogActivityFromEmailHandler implements OperationHandler
{
    /** The kind Party offers its contacts to activities under. */
    private const string CONTACT = 'contact';

    public function __construct(
        private ActivityWrites $writes,
        private ActivitiesJson $json,
        private UrlGeneratorInterface $urls,
        private InboxItems $items,
        private LinkableRecords $records,
    ) {
    }

    public static function input(): string
    {
        return LogActivityFromEmailInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof LogActivityFromEmailInput);
        $activity = $this->writes->create(new CreateActivityInput(
            typeId: $input->typeId,
            subject: $input->subject,
            plannedOn: $input->plannedOn,
            plannedAt: $input->plannedAt,
            done: $input->done,
            outcome: $input->outcome,
            links: $this->links($input->inboxItemId),
        ));

        return new OperationOutput($this->json->activity($activity), 'Created the activity from the e-mail.', $this->urls->generate(ActivitiesRoutes::ACTIVITY, ['activityId' => $activity->id()]));
    }

    /**
     * @return list<array{kind: string, id: string}>
     */
    private function links(string $itemId): array
    {
        $item = $this->items->find($itemId);
        if (!$item instanceof InboxItemView) {
            return [];
        }
        $records = [];
        foreach ([$item->contactId, $item->companyId] as $contactId) {
            if (null !== $contactId && $this->records->canLink(self::CONTACT, $contactId)) {
                $records[] = ['kind' => self::CONTACT, 'id' => $contactId];
            }
        }
        foreach ($item->outputs as $output) {
            if ($this->records->canLink($output->kind, $output->recordId)) {
                $records[] = ['kind' => $output->kind, 'id' => $output->recordId];
            }
        }

        return $records;
    }
}
