<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Health;

use App\Shared\Application\SystemMailer;
use App\Tenancy\Application\Schedule\Heartbeats;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Whether the installation does its work, cheaply enough for a monitor every
 * few minutes: the database answers, the worker beat within three minutes and
 * the schedule ticked within fifteen (else down), no message waits in the
 * failed transport, the data directory has at least the configured share of
 * its disk free and the installation has a system mailer (else degraded).
 * Numbers only: no names, no tenant data.
 */
final readonly class HealthCheck
{
    public function __construct(
        private Connection $connection,
        private Heartbeats $heartbeats,
        #[Autowire(service: 'messenger.transport.failed')]
        private TransportInterface $failed,
        private ClockInterface $clock,
        #[Autowire(param: 'kernel.share_dir')]
        private string $dataDir,
        #[Autowire('%env(int:HEALTH_DISK_MIN_FREE_PERCENT)%')]
        private int $minFreePercent,
        private SystemMailer $systemMail,
    ) {
    }

    public function run(): Health
    {
        return new Health($this->database() + [
            'failed_messages' => $this->failedMessages(),
            'disk_free_percent' => $this->disk(),
            // Without it no invitation can be sent.
            'system_mail' => ['status' => $this->systemMail->isConfigured() ? HealthStatus::Ok : HealthStatus::Degraded, 'value' => null],
        ]);
    }

    /**
     * @return array<string, array{status: HealthStatus, value: int|null}>
     */
    private function database(): array
    {
        try {
            $this->connection->executeQuery('SELECT 1');

            return [
                'database' => ['status' => HealthStatus::Ok, 'value' => null],
                'worker' => $this->beat(Heartbeats::WORKER, Heartbeats::WORKER_STALE_AFTER),
                'schedule' => $this->beat(Heartbeats::SCHEDULE, Heartbeats::SCHEDULE_STALE_AFTER),
            ];
        } catch (DbalException) {
            $down = ['status' => HealthStatus::Down, 'value' => null];

            return ['database' => $down, 'worker' => $down, 'schedule' => $down];
        }
    }

    /**
     * Seconds since the last beat; down when there was none or it is too old.
     *
     * @return array{status: HealthStatus, value: int|null}
     */
    private function beat(string $name, int $staleAfter): array
    {
        $last = $this->heartbeats->last($name);
        if (!$last instanceof \DateTimeImmutable) {
            return ['status' => HealthStatus::Down, 'value' => null];
        }
        $age = $this->clock->now()->getTimestamp() - $last->getTimestamp();

        return ['status' => $age > $staleAfter ? HealthStatus::Down : HealthStatus::Ok, 'value' => $age];
    }

    /**
     * @return array{status: HealthStatus, value: int|null}
     */
    private function failedMessages(): array
    {
        // A transport that cannot count, such as the in-memory one of the tests, keeps nothing.
        if (!$this->failed instanceof MessageCountAwareInterface) {
            return ['status' => HealthStatus::Ok, 'value' => null];
        }
        try {
            $count = $this->failed->getMessageCount();
        } catch (TransportException) {
            return ['status' => HealthStatus::Degraded, 'value' => null];
        }

        return ['status' => $count > 0 ? HealthStatus::Degraded : HealthStatus::Ok, 'value' => $count];
    }

    /**
     * The free share of the disk the data directory is on, or of its nearest
     * existing parent before the first file was stored.
     *
     * @return array{status: HealthStatus, value: int|null}
     */
    private function disk(): array
    {
        $dir = $this->dataDir;
        while (!is_dir($dir)) {
            $dir = \dirname($dir);
        }
        $percent = (int) floor(100 * (float) disk_free_space($dir) / max(1.0, (float) disk_total_space($dir)));

        return ['status' => $percent < $this->minFreePercent ? HealthStatus::Degraded : HealthStatus::Ok, 'value' => $percent];
    }
}
