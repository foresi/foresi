<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Integration\Inbox;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Foresi\Attachment\Application\AttachmentRefused;
use Foresi\Attachment\Application\AttachmentUpload;
use Foresi\Attachment\Application\UploadAttachment;
use Foresi\Inbox\Contract\InboxAttachmentContent;
use Foresi\Inbox\Contract\InboxItems;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;
use Foresi\Work\Application\Api\CreateTaskInput;
use Foresi\Work\Application\Api\WorkWrites;
use Foresi\Work\Infrastructure\Api\Operation\WorkAnswers;
use Foresi\Work\Infrastructure\Attachment\TaskAttachmentAccess;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * inbox.actions.create_task: the same task tasks.create makes, then the files
 * of the mail on it as the task screen keeps an upload, when asked. The task
 * stays when a file cannot be kept, for whatever reason; the answer says how many were not.
 */
final readonly class CreateTaskFromEmailHandler implements OperationHandler
{
    public function __construct(
        private WorkWrites $writes,
        private WorkAnswers $answer,
        private InboxItems $items,
        private UploadAttachment $upload,
        private EntityManagerInterface $em,
        private ManagerRegistry $registry,
        private LoggerInterface $logger = new NullLogger(),
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
            if (!$content instanceof InboxAttachmentContent) {
                ++$failed;
                continue;
            }
            // Made only once there is something to write, so that every file made is removed below.
            $path = tempnam(sys_get_temp_dir(), 'foresi-inbox-');
            if (false === $path) {
                ++$failed;
                continue;
            }
            try {
                if (false === file_put_contents($path, $content->bytes)) {
                    throw new AttachmentRefused(AttachmentRefused::FAILED);
                }
                $this->upload->upload(TaskAttachmentAccess::KIND, $taskId, new AttachmentUpload($path, '' === $content->name ? 'attachment' : $content->name, \strlen($content->bytes)));
            } catch (\Throwable $failure) {
                // Whatever the failure, the task exists and must still be recorded: ids and the class only, never the file name or message.
                if (!$failure instanceof AttachmentRefused) {
                    $this->logger->error('A file of an inbox item could not be copied onto its task.', ['item_id' => $itemId, 'task_id' => $taskId, 'failure' => $failure::class]);
                }
                ++$failed;
                if (!$this->em->isOpen()) {
                    // A database error closed the entity manager: the task is committed, so a fresh one reads it back and records it.
                    // Entities other services loaded before the reset are detached from the fresh manager: nothing here persists one.
                    $this->registry->resetManager();
                }
            } finally {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }

        return $failed;
    }
}
