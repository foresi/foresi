<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Schedule;

use App\Tenancy\Application\Schedule\OperatorTask;
use App\Tenancy\Application\Schedule\RunOperatorTask;
use App\Tenancy\Application\TenantContext;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Runs an operator task under a lock that does not wait: a run already going
 * elsewhere makes this one a no-op, and the next one comes at the task's pace.
 * A failure is logged and ends here, as retrying the message would only bunch
 * runs up; the lock is freed either way. A task never runs inside a tenant.
 */
#[AsMessageHandler]
final readonly class RunOperatorTaskHandler
{
    /** Seconds a run may hold its lock; a crashed worker frees it after that. */
    public const float LOCK_TTL = 600.0;

    /**
     * @param iterable<OperatorTask> $tasks
     */
    public function __construct(
        private LockFactory $locks,
        private TenantContext $context,
        private LoggerInterface $logger,
        #[AutowireIterator('app.operator_task')]
        private iterable $tasks,
    ) {
    }

    public function __invoke(RunOperatorTask $message): void
    {
        $task = $this->task($message->task);
        if ($this->context->has()) {
            throw new UnrecoverableMessageHandlingException(\sprintf('The operator task "%s" runs without a tenant; it was sent from inside one.', $message->task));
        }

        $lock = $this->locks->createLock('operator-task-'.$task->name(), self::LOCK_TTL);
        if (!$lock->acquire()) {
            return;
        }

        try {
            $task->run();
        } catch (\Throwable $failure) {
            $this->logger->error('The operator task {task} failed; its next run comes at its usual time.', ['task' => $task->name(), 'exception' => $failure]);
        } finally {
            $lock->release();
        }
    }

    private function task(string $name): OperatorTask
    {
        foreach ($this->tasks as $task) {
            if ($task->name() === $name) {
                return $task;
            }
        }

        throw new UnrecoverableMessageHandlingException(\sprintf('No operator task is named "%s".', $name));
    }
}
