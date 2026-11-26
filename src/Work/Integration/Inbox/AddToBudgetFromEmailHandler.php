<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Integration\Inbox;

use Foresi\Activities\Contract\ActivityRecorder;
use Foresi\Inbox\Contract\InboxItems;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;
use Foresi\Work\Application\Api\AddProjectBudgetChangeInput;
use Foresi\Work\Application\Api\BudgetWrites;
use Foresi\Work\Infrastructure\Api\Operation\WorkAnswers;

/**
 * inbox.actions.add_to_budget: the same change work.project_budget.add_change
 * makes, with the e-mail activity of the item as its source.
 *
 * The activity is made first when the item has none (Inbox does that in its own
 * transaction, under the item's lock) and linked to the project, since the
 * source of a change must be linked to the project. The change is added after
 * that, in Work's own transaction. When Work refuses the change, such as for
 * hours that take the budget below zero, the activity stays made and linked to
 * the project: it is the item's one e-mail activity, which the next output
 * would make anyway, and nothing else came of it. The answer is the budget, as
 * the budget screen reads it, with the id of the change, which Inbox stores as
 * the output of the item.
 */
final readonly class AddToBudgetFromEmailHandler implements OperationHandler
{
    public function __construct(
        private BudgetWrites $writes,
        private WorkAnswers $answer,
        private InboxItems $items,
        private ActivityRecorder $activities,
    ) {
    }

    public static function input(): string
    {
        return AddToBudgetFromEmailInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof AddToBudgetFromEmailInput);

        $activityId = $this->items->emailActivity($input->inboxItemId);
        $this->activities->link($activityId, ActiveProjectSuggester::KIND, $input->projectId);
        $changeId = $this->writes->add(new AddProjectBudgetChangeInput(
            projectId: $input->projectId,
            hours: $input->hours,
            minutes: $input->minutes,
            effectiveOn: $input->effectiveOn,
            note: $input->note,
            sourceActivityId: $activityId,
        ));
        $answer = $this->answer->budget($input->projectId, 'Added the change to the budget of the project from the e-mail.');

        return new OperationOutput(['id' => $changeId] + $answer->data, $answer->summary, $answer->location);
    }
}
