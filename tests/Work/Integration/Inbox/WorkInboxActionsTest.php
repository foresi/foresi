<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Integration\Inbox;

use Doctrine\DBAL\Driver\PDO\Exception as DriverException;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\ORM\EntityManagerInterface;
use Foresi\Attachment\Application\AttachmentFiles;
use Foresi\Attachment\Application\AttachmentRefused;
use Foresi\Attachment\Application\Attachments;
use Foresi\Attachment\Application\VisibleAttachments;
use Foresi\Attachment\Domain\Attachment;
use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Foresi\Tests\Inbox\Support\ContainerPreFills;
use Foresi\Tests\Inbox\Support\InboxMail;
use Foresi\Tests\Inbox\Support\ItemViews;
use Foresi\Tests\Inbox\Support\McpToolCalls;
use Foresi\Tests\Inbox\Support\RebuiltServices;
use Foresi\Tests\Shared\Infrastructure\Mcp\RecordingLogger;
use Foresi\Work\Application\ManageProjects;
use Foresi\Work\Application\ManageTasks;
use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\Task;
use Foresi\Work\Infrastructure\Attachment\TaskAttachmentAccess;
use Foresi\Work\Integration\Activities\TaskActivityTarget;
use Foresi\Work\Integration\Inbox\CreateTaskFromEmailHandler;
use Foresi\Work\Integration\Inbox\CreateTaskFromEmailInput;
use Foresi\Work\Integration\Inbox\CreateTaskFromEmailPreFill;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The inbox action of Work: a task made from an inbox item with the project
 * of its client pre-selected, recorded as an output of the item, the one
 * e-mail activity of the item linked to the task and to the project.
 */
final class WorkInboxActionsTest extends WorkInboxTestCase
{
    // The helpers of the traits are private: each test class takes them for itself.
    use ContainerPreFills;
    use ItemViews;
    use McpToolCalls;
    use RebuiltServices;

    public function testCreateTaskFromEmailLinksTheTaskAndExtendsTheOneEmailActivity(): void
    {
        $project = $this->project('Office move');
        $itemId = $this->receive(InboxMail::plain($this->address));
        $this->match($itemId);

        $this->send('POST', '/api/v1/inbox/items/'.$itemId.'/tasks', ['project_id' => $project->id(), 'title' => 'Answer the offer', 'description' => 'See the mail.'], $this->agent);
        self::assertResponseStatusCodeSame(201);
        $taskId = $this->at('data', 'id');
        self::assertIsString($taskId);
        self::assertSame(['Answer the offer', $project->id()], [$this->at('data', 'title'), $this->at('data', 'project', 'id')]);

        $this->get('/api/v1/inbox/items/'.$itemId, secret: $this->agent);
        self::assertSame('done', $this->at('data', 'state'));
        $outputs = (array) $this->at('data', 'outputs');
        self::assertCount(1, $outputs);
        $output = $outputs[0];
        self::assertIsArray($output);
        self::assertSame(['task', $taskId, true, 'Answer the offer', '/tasks/'.$taskId], [$output['kind'], $output['id'], $output['available'], $output['label'], $output['url']]);
        $activityId = $this->at('data', 'email_activity_id');
        self::assertIsString($activityId);
        self::assertEqualsCanonicalizing([['contact', $this->customerId], ['task', $taskId], ['project', $project->id()]], $this->linksOf($activityId));
    }

    public function testLinkAfterTheFactAddsAnOutputForARecordMadeElsewhere(): void
    {
        $project = $this->project('Office move');
        $itemId = $this->receive(InboxMail::plain($this->address));

        $this->send('POST', '/api/v1/tasks', ['project_id' => $project->id(), 'title' => 'Made by hand'], $this->agent);
        self::assertResponseStatusCodeSame(201);
        $taskId = $this->at('data', 'id');
        self::assertIsString($taskId);

        $this->send('POST', '/api/v1/inbox/items/'.$itemId.'/outputs', ['kind' => 'task', 'id' => $taskId], $this->agent);
        self::assertResponseStatusCodeSame(201);
        $this->get('/api/v1/inbox/items/'.$itemId, secret: $this->agent);
        $output = ((array) $this->at('data', 'outputs'))[0] ?? null;
        self::assertIsArray($output);
        self::assertSame(['task', $taskId, true, 'Made by hand'], [$output['kind'], $output['id'], $output['available'], $output['label']]);
        $activityId = $this->at('data', 'email_activity_id');
        self::assertIsString($activityId);
        self::assertSame([['task', $taskId]], $this->linksOf($activityId));
    }

    public function testTwoActiveProjectsPreselectNone(): void
    {
        $first = $this->project('Office move');
        $second = $this->project('Roof audit');
        $itemId = $this->receive(InboxMail::plain($this->address));
        $this->match($itemId);

        $task = $this->actions($itemId)['inbox.actions.create_task'];
        self::assertArrayNotHasKey('project_id', $task['prefill']);
        self::assertSame(['project_id' => [$first->id() => 'Office move', $second->id() => 'Roof audit']], $task['choices']);
    }

    public function testAnArchivedProjectIsIgnored(): void
    {
        $active = $this->project('Office move');
        $old = $this->project('Old roof');
        $this->as(fn () => $this->service(ManageProjects::class)->archive($old->id()));
        $itemId = $this->receive(InboxMail::plain($this->address));
        $this->match($itemId);

        $task = $this->actions($itemId)['inbox.actions.create_task'];
        self::assertSame($active->id(), $task['prefill']['project_id']);
        self::assertSame(['project_id' => [$active->id() => 'Office move']], $task['choices']);
    }

    public function testAnItemOfNoClientOffersAllTheActiveProjectsAndPreselectsNone(): void
    {
        $project = $this->project('Office move', false);
        $itemId = $this->receive(InboxMail::plain($this->address, from: 'Someone <someone@unknown.example.com>'));

        $task = $this->actions($itemId)['inbox.actions.create_task'];
        self::assertArrayNotHasKey('project_id', $task['prefill']);
        self::assertSame(['project_id' => [$project->id() => 'Office move']], $task['choices']);
    }

    public function testCopyAttachmentsCopiesTheFilesOfTheMailOntoTheTask(): void
    {
        $project = $this->project('Office move');
        $itemId = $this->receive($this->withAttachment());

        self::assertTrue($this->actions($itemId)['inbox.actions.create_task']['prefill']['copy_attachments']);
        $this->send('POST', '/api/v1/inbox/items/'.$itemId.'/tasks', ['project_id' => $project->id(), 'title' => 'Read the offer'], $this->agent);
        self::assertResponseStatusCodeSame(201);
        $taskId = $this->at('data', 'id');
        self::assertIsString($taskId);

        self::assertSame(['offer.pdf'], $this->attachmentNames($taskId));
    }

    public function testCopyAttachmentsCanBeUnticked(): void
    {
        $project = $this->project('Office move');
        $itemId = $this->receive($this->withAttachment());

        $this->send('POST', '/api/v1/inbox/items/'.$itemId.'/tasks', ['project_id' => $project->id(), 'title' => 'Read the offer', 'copy_attachments' => false], $this->agent);
        self::assertResponseStatusCodeSame(201);
        $taskId = $this->at('data', 'id');
        self::assertIsString($taskId);

        self::assertSame([], $this->attachmentNames($taskId));
    }

    public function testTheTaskActionFillsTheDescriptionWithAtMostTheLimit(): void
    {
        $itemId = $this->receive(InboxMail::plain($this->address, body: str_repeat('x', CreateTaskFromEmailPreFill::MAX_DESCRIPTION + 50)."\r\n"));

        $description = $this->actions($itemId)['inbox.actions.create_task']['prefill']['description'] ?? null;
        self::assertIsString($description);
        self::assertSame(CreateTaskFromEmailPreFill::MAX_DESCRIPTION, mb_strlen($description));
    }

    public function testTheTaskPreFillTakesTheSubjectAndTheTextTrimmedAndCut(): void
    {
        $item = $this->itemView(subject: '  '.str_repeat('ž', Task::MAX_TITLE_LENGTH + 20).'  ', text: "\n  ".str_repeat('é', CreateTaskFromEmailPreFill::MAX_DESCRIPTION + 10)."  \n");

        $values = $this->as(fn (): array => $this->prefillOf('inbox.actions.create_task', $item)->values, $this->user);

        self::assertSame(str_repeat('ž', Task::MAX_TITLE_LENGTH), $values['title']);
        self::assertSame(str_repeat('é', CreateTaskFromEmailPreFill::MAX_DESCRIPTION), $values['description']);
        self::assertFalse($values['copyAttachments']);
        self::assertTrue($values['markDone']);
    }

    public function testTheTaskIsAnsweredWithItsAddress(): void
    {
        $project = $this->project('Office move');
        $itemId = $this->receive(InboxMail::plain($this->address));

        $this->send('POST', '/api/v1/inbox/items/'.$itemId.'/tasks', ['project_id' => $project->id(), 'title' => 'Answer the offer'], $this->agent);

        self::assertResponseStatusCodeSame(201);
        $taskId = $this->at('data', 'id');
        self::assertIsString($taskId);
        self::assertSame('/api/v1/tasks/'.$taskId, $this->client->getResponse()->headers->get('Location'));
    }

    public function testTheFilesCopiedAreNotMentionedAndNoTemporaryFileStays(): void
    {
        $project = $this->project('Office move');
        $bytes = '%PDF-1.4 '.bin2hex(random_bytes(8));
        $itemId = $this->receive($this->withAttachment($bytes));

        $answer = $this->createTask($itemId, $project, copyAttachments: true);

        self::assertSame('Created the task Read the offer from the e-mail.', $answer->summary);
        self::assertNotNull($answer->location);
        self::assertSame(['offer.pdf'], $this->attachmentNames($this->taskIdOf($answer)));
        self::assertSame([], $this->leftOver($bytes));
    }

    public function testNothingIsSaidOfFilesThatWereNotAskedFor(): void
    {
        $project = $this->project('Office move');
        $itemId = $this->receive($this->withAttachment('%PDF-1.4 not asked'));

        $answer = $this->createTask($itemId, $project, copyAttachments: false);

        self::assertSame('Created the task Read the offer from the e-mail.', $answer->summary);
        self::assertSame([], $this->attachmentNames($this->taskIdOf($answer)));
    }

    public function testAFileThatCannotBeKeptIsCountedLoggedByIdsAndLeavesNoTemporaryFile(): void
    {
        $project = $this->project('Office move');
        $bytes = '%PDF-1.4 '.bin2hex(random_bytes(8));
        $itemId = $this->receive($this->withAttachment($bytes));
        $this->storageThrows(new \LogicException('The storage broke offer.pdf.'));
        $logger = new RecordingLogger();

        $answer = $this->createTask($itemId, $project, copyAttachments: true, logger: $logger);

        self::assertSame('Created the task Read the offer from the e-mail. 1 of the files of the mail could not be copied onto it.', $answer->summary);
        self::assertSame([], $this->attachmentNames($this->taskIdOf($answer)));
        self::assertSame([['error', 'A file of an inbox item could not be copied onto its task.', ['item_id' => $itemId, 'task_id' => $this->taskIdOf($answer), 'failure' => \LogicException::class]]], $logger->records, 'Ids and the class: not the name of the file, nor the message.');
        self::assertSame([], $this->leftOver($bytes));
    }

    public function testAFileTheStorageRefusesIsCountedButNotLogged(): void
    {
        $project = $this->project('Office move');
        $itemId = $this->receive($this->withAttachment('%PDF-1.4 refused'));
        $this->storageThrows(new AttachmentRefused(AttachmentRefused::FAILED));
        $logger = new RecordingLogger();

        $answer = $this->createTask($itemId, $project, copyAttachments: true, logger: $logger);

        self::assertSame('Created the task Read the offer from the e-mail. 1 of the files of the mail could not be copied onto it.', $answer->summary);
        self::assertSame([], $logger->records, 'A refusal is the ordinary way a file is not kept.');
    }

    public function testTheTemporaryFileIsRemovedEvenWhenTheLoggerFails(): void
    {
        $project = $this->project('Office move');
        $bytes = '%PDF-1.4 '.bin2hex(random_bytes(8));
        $itemId = $this->receive($this->withAttachment($bytes));
        $this->storageThrows(new \LogicException('The storage broke.'));
        $logger = new class extends \Psr\Log\AbstractLogger {
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                throw new \RuntimeException('The log is gone.');
            }
        };

        try {
            $this->createTask($itemId, $project, copyAttachments: true, logger: $logger);
            self::fail('The failure of the logger was swallowed.');
        } catch (\RuntimeException $failure) {
            self::assertSame('The log is gone.', $failure->getMessage());
        }

        self::assertSame([], $this->leftOver($bytes));
    }

    public function testAFileThatCannotBeCopiedForAnyReasonLeavesTheTaskAsAnOutput(): void
    {
        $project = $this->project('Office move');
        $itemId = $this->receive($this->withAttachment());
        // A storage failure that is not an AttachmentRefused nor a RuntimeException.
        self::getContainer()->set(AttachmentFiles::class, new class implements AttachmentFiles {
            public function store(string $attachmentId, string $sourcePath): string
            {
                throw new \LogicException('The storage broke.');
            }

            public function read(string $attachmentId, string $key, string $sha256): string
            {
                throw new \LogicException('The storage broke.');
            }

            public function locate(string $attachmentId, string $key): string
            {
                throw new \LogicException('The storage broke.');
            }

            public function remove(string $key): void
            {
            }
        });

        $this->send('POST', '/api/v1/inbox/items/'.$itemId.'/tasks', ['project_id' => $project->id(), 'title' => 'Read the offer'], $this->agent);
        self::assertResponseStatusCodeSame(201);
        $taskId = $this->at('data', 'id');
        self::assertIsString($taskId);

        $this->get('/api/v1/inbox/items/'.$itemId, secret: $this->agent);
        self::assertSame('done', $this->at('data', 'state'));
        self::assertSame([['task', $taskId]], array_map(static fn (mixed $output): array => \is_array($output) ? [$output['kind'], $output['id']] : [], (array) $this->at('data', 'outputs')));
        self::assertSame([], $this->attachmentNames($taskId));
    }

    public function testADatabaseErrorWhileKeepingAFileLeavesTheTaskAsAnOutput(): void
    {
        $project = $this->project('Office move');
        $itemId = $this->receive($this->withAttachment());
        $em = $this->em();
        // The flush of the attachment fails in the database and closes the entity manager, as a constraint violation does.
        self::getContainer()->set(Attachments::class, new readonly class($em) implements Attachments {
            public function __construct(private EntityManagerInterface $em)
            {
            }

            public function of(string $ownerKind, string $ownerId): array
            {
                return [];
            }

            public function get(string $id): ?Attachment
            {
                return null;
            }

            public function withDeleted(string $id): ?Attachment
            {
                return null;
            }

            public function deletedOf(string $ownerKind, string $ownerId): array
            {
                return [];
            }

            public function deletedBefore(\DateTimeImmutable $moment): array
            {
                return [];
            }

            public function allOf(string $ownerKind, array $ownerIds): array
            {
                return [];
            }

            public function remove(Attachment $attachment): void
            {
            }

            public function add(Attachment $attachment): void
            {
            }

            public function flush(): void
            {
                $this->em->close();

                throw new ConnectionLost(DriverException::new(new \PDOException('The database went away.')), null);
            }
        });

        $this->send('POST', '/api/v1/inbox/items/'.$itemId.'/tasks', ['project_id' => $project->id(), 'title' => 'Read the offer'], $this->agent);
        self::assertResponseStatusCodeSame(201);
        $taskId = $this->at('data', 'id');
        self::assertIsString($taskId);

        $this->get('/api/v1/inbox/items/'.$itemId, secret: $this->agent);
        self::assertSame('done', $this->at('data', 'state'));
        self::assertSame([['task', $taskId]], array_map(static fn (mixed $output): array => \is_array($output) ? [$output['kind'], $output['id']] : [], (array) $this->at('data', 'outputs')));
    }

    public function testAProjectThePersonCannotSeeIsNeverProposed(): void
    {
        $visible = $this->project('Office move');
        $this->chosenProject('Secret');
        $itemId = $this->receive(InboxMail::plain($this->address));
        $this->match($itemId);

        $task = $this->actions($itemId)['inbox.actions.create_task'];
        self::assertSame($visible->id(), $task['prefill']['project_id'] ?? null, 'The one project the person sees.');
        self::assertSame(['project_id' => [$visible->id() => 'Office move']], $task['choices']);
    }

    public function testOnlyAProjectThePersonCannotSeeProposesNothingAndNamesNothing(): void
    {
        $secret = $this->chosenProject('Secret');
        $itemId = $this->receive(InboxMail::plain($this->address));
        $this->match($itemId);

        $task = $this->actions($itemId)['inbox.actions.create_task'];
        self::assertArrayNotHasKey('project_id', $task['prefill']);
        self::assertSame(['project_id' => []], $task['choices']);
        self::assertStringNotContainsString($secret->id(), (string) json_encode($task));
    }

    public function testTheTaskTargetFollowsWhatThePersonSees(): void
    {
        $project = $this->project('Office move');
        $open = $this->task($project, 'Open task');
        $private = $this->privateTask($project, 'Private task');
        $trashed = $this->task($project, 'Trashed task');
        $this->as(fn () => $this->service(ManageTasks::class)->delete($trashed));
        $archivedProject = $this->project('Old roof');
        $inArchived = $this->task($archivedProject, 'Task of an archived project');
        $this->as(fn () => $this->service(ManageProjects::class)->archive($archivedProject->id()));

        $target = $this->service(TaskActivityTarget::class);
        $seen = $this->as(static fn (): array => [
            [$target->canSee('task', $open), $target->canLink('task', $open), $target->label('task', $open), $target->url('task', $open)],
            [$target->canSee('task', $private), $target->canLink('task', $private), $target->label('task', $private), $target->url('task', $private)],
            [$target->canSee('task', $trashed), $target->canLink('task', $trashed), $target->label('task', $trashed), $target->url('task', $trashed)],
            [$target->canSee('task', $inArchived), $target->canLink('task', $inArchived), $target->label('task', $inArchived), $target->url('task', $inArchived)],
            [$target->canSee('project', $open), $target->canSee('task', 'not-an-id')],
        ], $this->user);

        self::assertSame([true, true, 'Open task', '/tasks/'.$open], $seen[0]);
        self::assertSame([false, false, null, null], $seen[1], 'A private task is not the member\'s to see.');
        self::assertSame([false, false, null, null], $seen[2], 'A task in the trash is gone.');
        self::assertSame([true, false, 'Task of an archived project', '/tasks/'.$inArchived], $seen[3], 'Seen, but an archived project takes no new activity.');
        self::assertSame([false, false], $seen[4]);
        $asOwner = $this->as(static fn (): array => [$target->canSee('task', $private), $target->label('task', $private)]);
        self::assertSame([true, 'Private task'], $asOwner, 'The owner sees every task.');
    }

    /**
     * @return list<string>
     */
    private function attachmentNames(string $taskId): array
    {
        $tokens = self::getContainer()->get(TokenStorageInterface::class);
        $tokens->setToken(new UsernamePasswordToken(SecurityUser::fromUser($this->user), 'main', ['ROLE_USER']));
        try {
            return array_map(static fn (Attachment $file): string => $file->fileName(), $this->service(VisibleAttachments::class)->of(TaskAttachmentAccess::KIND, $taskId));
        } finally {
            $tokens->setToken(null);
        }
    }

    private function createTask(string $itemId, Project $project, bool $copyAttachments, ?\Psr\Log\LoggerInterface $logger = null): \Foresi\Shared\Contract\Operation\OperationOutput
    {
        $handler = $this->rebuilt(self::getContainer()->get(CreateTaskFromEmailHandler::class), $logger instanceof \Psr\Log\LoggerInterface ? ['logger' => $logger] : []);

        return $this->as(static fn () => $handler->handle(new CreateTaskFromEmailInput($itemId, $project->id(), 'Read the offer', copyAttachments: $copyAttachments)), $this->user);
    }

    private function taskIdOf(\Foresi\Shared\Contract\Operation\OperationOutput $answer): string
    {
        $id = $answer->data['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    private function storageThrows(\Throwable $failure): void
    {
        self::getContainer()->set(AttachmentFiles::class, new readonly class($failure) implements AttachmentFiles {
            public function __construct(private \Throwable $failure)
            {
            }

            public function store(string $attachmentId, string $sourcePath): string
            {
                throw $this->failure;
            }

            public function read(string $attachmentId, string $key, string $sha256): string
            {
                throw $this->failure;
            }

            public function locate(string $attachmentId, string $key): string
            {
                throw $this->failure;
            }

            public function remove(string $key): void
            {
            }
        });
    }

    /**
     * @return list<string> the temporary files of the copy that hold these bytes
     */
    private function leftOver(string $bytes): array
    {
        $files = glob(sys_get_temp_dir().'/foresi-inbox-*');

        return array_values(array_filter(false === $files ? [] : $files, static fn (string $file): bool => is_file($file) && $bytes === file_get_contents($file)));
    }

    private function withAttachment(string $bytes = '%PDF-1.4 example'): string
    {
        return implode("\r\n", [
            'Authentication-Results: '.InboxMail::verifiedBy('client-a.example.com'),
            'From: Client A <client.a@client-a.example.com>',
            'To: '.$this->address,
            'Subject: Offer with a file',
            'Date: Tue, 24 Nov 2026 09:35:00 +0100',
            'Message-ID: <offer-2@client-a.example.com>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="b1"',
            '',
            '--b1',
            'Content-Type: text/plain; charset=utf-8',
            'Content-Transfer-Encoding: 8bit',
            '',
            'Hello, the offer is attached.',
            '--b1',
            'Content-Type: application/pdf; name="offer.pdf"',
            'Content-Disposition: attachment; filename="offer.pdf"',
            'Content-Transfer-Encoding: base64',
            '',
            base64_encode($bytes),
            '--b1--',
            '',
        ]);
    }
}
