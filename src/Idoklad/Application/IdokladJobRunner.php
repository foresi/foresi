<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Application;

use App\Idoklad\Application\Import\RunIdokladImport;
use App\Idoklad\Domain\IdokladJob;
use App\Idoklad\Domain\IdokladJobKind;
use App\Idoklad\Domain\IdokladPull;
use App\Idoklad\Domain\ImportMode;
use App\Invoicing\Application\ReminderSettings;
use App\Party\Application\LegalEntities;
use App\Party\Application\TransactionalWork;
use Psr\Clock\ClockInterface;

/**
 * Does a job a screen asked for. A pull copies the account, or what changed
 * since the last pull that succeeded, and then the PDFs in batches, each batch
 * stored as it comes. A check or a run imports the snapshot; a run that ended
 * well sets the day reminders start from. A job that ended is not done again,
 * so a second delivery of its message does nothing; one a stopped worker left
 * running is done again, which a pull and an import bear.
 */
final readonly class IdokladJobRunner
{
    public const int PDF_BATCH = 100;

    /** Error texts the screens translate; any other is shown as iDoklad's failure said it. */
    public const string ACCESS_DENIED = 'idoklad.error.access_denied';
    public const string TARIFF = 'idoklad.error.tariff';
    public const string NOT_CONNECTED = 'idoklad.error.not_connected';
    public const string UNEXPECTED = 'idoklad.error.unexpected';

    public function __construct(
        private IdokladJobs $jobs,
        private PullIdokladSnapshot $pull,
        private IdokladSnapshot $snapshot,
        private RunIdokladImport $import,
        private ReminderSettings $reminders,
        private LegalEntities $firms,
        private TransactionalWork $work,
        private ClockInterface $clock,
    ) {
    }

    public function process(string $jobId): void
    {
        $job = $this->jobs->find($jobId);
        if (!$job instanceof IdokladJob || !$job->state()->isBusy()) {
            return;
        }
        $this->work->run(fn () => $job->start($this->now()));

        try {
            [$resultId, $error] = $job->kind()->pulls() ? $this->pull($job) : $this->import($job);
        } catch (\Throwable $failure) {
            $this->finish($jobId, null, self::UNEXPECTED);

            throw $failure;
        }
        $this->finish($jobId, $resultId, $error);
    }

    /**
     * Maps a failure to the text a screen shows.
     */
    public static function errorOf(IdokladFailure $failure): string
    {
        return match (true) {
            $failure instanceof IdokladAccessDenied => self::ACCESS_DENIED,
            $failure instanceof IdokladTariffWithoutApi => self::TARIFF,
            $failure instanceof IdokladNotConnected => self::NOT_CONNECTED,
            default => $failure->getMessage(),
        };
    }

    /**
     * @return array{0: string|null, 1: string|null} the pull and the error
     */
    private function pull(IdokladJob $job): array
    {
        $firm = $job->firm();
        $since = IdokladJobKind::Changes === $job->kind() ? $this->snapshot->lastSucceededPull($firm)?->latestChange() : null;
        try {
            $pull = $this->pull->pull($firm, $since);
            do {
                $batch = $this->pull->downloadPdfs($firm, self::PDF_BATCH);
                $this->work->run(fn () => $pull->addRequests($batch->requestsUsed));
            } while (self::PDF_BATCH === $batch->downloaded);
        } catch (IdokladFailure $failure) {
            $latest = $this->snapshot->pulls($firm)[0] ?? null;

            return [$latest instanceof IdokladPull ? $latest->id() : null, self::errorOf($failure)];
        }

        return [$pull->id(), null];
    }

    /**
     * @return array{0: string, 1: string|null} the import run and the error
     */
    private function import(IdokladJob $job): array
    {
        $mode = IdokladJobKind::Run === $job->kind() ? ImportMode::Run : ImportMode::Check;
        $run = $this->import->run($job->firm(), $mode, $job->options());
        $remindFrom = $job->remindFrom();
        if (ImportMode::Run === $mode && null === $run->error() && $remindFrom instanceof \DateTimeImmutable) {
            $firmId = $run->firm()->id();
            $this->work->run(function () use ($firmId, $remindFrom): void {
                $settings = $this->reminders->for($this->firms->get($firmId));
                $settings->remindFrom($remindFrom);
                $this->reminders->save($settings);
            });
        }

        return [$run->id(), $run->error()];
    }

    private function finish(string $jobId, ?string $resultId, ?string $error): void
    {
        // Read again: an import's rollback may have detached what was loaded before.
        $this->work->run(function () use ($jobId, $resultId, $error): void {
            $this->jobs->find($jobId)?->finish($this->now(), $resultId, $error);
        });
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
