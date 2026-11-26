<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Integration\Inbox;

use Foresi\Attachment\Application\AttachmentRefused;
use Foresi\Attachment\Application\AttachmentUpload;
use Foresi\Attachment\Application\UploadAttachment;
use Foresi\Inbox\Contract\InboxItems;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;
use Foresi\Work\Application\Api\CreateTaskInput;
use Foresi\Work\Application\Api\WorkWrites;
use Foresi\Work\Infrastructure\Api\Operation\WorkAnswers;
use Foresi\Work\Infrastructure\Attachment\TaskAttachmentAccess;

/**
 * inbox.actions.create_task: the same task tasks.create makes, then the files
 * of the mail on it as the task screen keeps an upload, when asked. The task
 * stays when a file cannot be kept; the answer says how many were not.
 */
final readonly class CreateTaskFromEmailHandler implements OperationHandler
{
    public function __construct(
        private WorkWrites $writes,
        private WorkAnswers $answer,
        private InboxItems $items,
        private UploadAttachment $upload,
    ) {
    }

    public static function input(): string
    {
        return CreateTaskFromEmailInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof CreateTaskFromEmailInput);

        $taskId = $this->writes->createTask(new CreateTaskInput(
            projectId: $input->projectId,
            title: $input->title,
            description: $input->description,
            responsibleId: $input->responsibleId,
            dueOn: $input->dueOn,
        ));
        $failed = $input->copyAttachments ? $this->copyAttachments($input->inboxItemId, $taskId) : 0;
        $output = $this->answer->task($taskId, 'Created the task %s from the e-mail.', true);
        if (0 === $failed) {
            return $output;
        }

        return new OperationOutput($output->data, $output->summary.\sprintf(' %d of the files of the mail could not be copied onto it.', $failed), $output->location);
    }

    /**
     * @return int how many files of the mail could not be kept on the task
     */
    private function copyAttachments(string $itemId, string $taskId): int
    {
        $view = $this->items->find($itemId);
        $failed = 0;
        foreach ($view->attachments ?? [] as $attachment) {
            $content = $this->items->attachmentContent($itemId, $attachment->index);
            $path = tempnam(sys_get_temp_dir(), 'foresi-inbox-');
            if (!$content instanceof \Foresi\Inbox\Contract\InboxAttachmentContent || false === $path) {
                ++$failed;
                continue;
            }
            try {
                if (false === file_put_contents($path, $content->bytes)) {
                    throw new AttachmentRefused(AttachmentRefused::FAILED);
                }
                $this->upload->upload(TaskAttachmentAccess::KIND, $taskId, new AttachmentUpload($path, '' === $content->name ? 'attachment' : $content->name, \strlen($content->bytes)));
            } catch (AttachmentRefused) {
                ++$failed;
            } finally {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }

        return $failed;
    }
}
