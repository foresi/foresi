<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Infrastructure\Controller;

use App\Identity\Application\OwnerCheck;
use App\Idoklad\Application\ConnectIdoklad;
use App\Idoklad\Application\IdokladConnections;
use App\Idoklad\Application\IdokladFailure;
use App\Idoklad\Application\IdokladJobRunner;
use App\Idoklad\Application\IdokladJobs;
use App\Idoklad\Application\IdokladSnapshot;
use App\Idoklad\Application\Import\IdokladImportRuns;
use App\Idoklad\Application\Import\UndoIdokladImport;
use App\Idoklad\Application\StartIdokladJob;
use App\Idoklad\Application\VerifyIdokladConnection;
use App\Idoklad\Domain\IdokladConnection;
use App\Idoklad\Domain\IdokladImportRun;
use App\Idoklad\Domain\IdokladJob;
use App\Idoklad\Domain\IdokladJobKind;
use App\Idoklad\Domain\IdokladJobState;
use App\Idoklad\Domain\IdokladPull;
use App\Idoklad\Domain\ImportMode;
use App\Idoklad\Domain\ImportOptions;
use App\Idoklad\Domain\InvalidIdokladConnection;
use App\Invoicing\Application\UndoBlocker;
use App\Party\Application\LegalEntities;
use App\Party\Application\UnknownLegalEntity;
use App\Party\Domain\LegalEntity;
use App\Tenancy\Application\TenantClock;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The move from iDoklad of one firm, for the owner, as the invoicing settings
 * are: connect, pull, check, run and the result, with an undo for 30 days and
 * the history of pulls and runs. Pulls, checks and runs are background jobs;
 * while one waits or runs the firm's page shows its progress. A firm or run of
 * another tenant is never found, so it answers 404 like one that does not exist.
 */
final class IdokladImportController extends AbstractController
{
    public const string TOKEN = 'idoklad_import';

    /** Requests a month on the Oblíbený tariff; other tariffs differ. */
    public const int MONTHLY_REQUESTS = 7500;

    public function __construct(
        private readonly LegalEntities $firms,
        private readonly IdokladConnections $connections,
        private readonly ConnectIdoklad $connect,
        private readonly VerifyIdokladConnection $verify,
        private readonly IdokladSnapshot $snapshot,
        private readonly IdokladJobs $jobs,
        private readonly StartIdokladJob $start,
        private readonly IdokladImportRuns $runs,
        private readonly UndoIdokladImport $undo,
        private readonly IdokladScreens $screens,
        private readonly OwnerCheck $owner,
        private readonly TenantClock $tenantClock,
        private readonly ClockInterface $clock,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/import/idoklad', name: 'app_idoklad_firms', methods: ['GET'])]
    public function firms(): Response
    {
        $this->denyUnlessOwner();
        $firms = $this->firms->active();
        if (1 === \count($firms)) {
            return $this->redirectToRoute('app_idoklad', ['id' => $firms[0]->id()]);
        }

        return $this->render('idoklad/firms.html.twig', [
            'columns' => [['key' => 'name', 'label' => $this->translator->trans('idoklad.firms.column.name')]],
            'rows' => array_map(fn (LegalEntity $firm): array => ['id' => $firm->id(), 'href' => $this->generateUrl('app_idoklad', ['id' => $firm->id()]), 'cells' => ['name' => $firm->name()]], $firms),
        ]);
    }

    /**
     * The step the firm is at: the progress of a job that waits or runs, the
     * result of the latest run, the check report, the pull or the connection.
     */
    #[Route('/import/idoklad/{id}', name: 'app_idoklad', requirements: ['id' => Requirement::UUID_V7], methods: ['GET'])]
    public function show(string $id): Response
    {
        $firm = $this->firm($id);
        $job = $this->jobs->latest($firm);
        if ($job instanceof IdokladJob && $job->state()->isBusy()) {
            return $this->render('idoklad/progress.html.twig', $this->frame($firm, $job) + [
                'firm' => $firm,
                'stepper' => $this->screens->stepper($this->screens->stepOf($job)),
                'crumbs' => $this->screens->crumbs($firm),
            ]);
        }
        if ($job instanceof IdokladJob && IdokladJobKind::Run === $job->kind() && IdokladJobState::Done === $job->state()) {
            return $this->redirectToRoute('app_idoklad_run', ['id' => $id, 'run' => $job->resultId()]);
        }
        $to = match (true) {
            !$this->connections->for($firm) instanceof IdokladConnection && [] === $this->snapshot->pulls($firm) => 'app_idoklad_connect',
            $job instanceof IdokladJob && !$job->kind()->pulls() => 'app_idoklad_check',
            default => 'app_idoklad_pull',
        };

        return $this->redirectToRoute($to, ['id' => $id]);
    }

    #[Route('/import/idoklad/{id}/progress', name: 'app_idoklad_progress', requirements: ['id' => Requirement::UUID_V7], methods: ['GET'])]
    public function progress(string $id): Response
    {
        $firm = $this->firm($id);
        $job = $this->jobs->latest($firm) ?? throw new NotFoundHttpException();
        $response = $this->render('idoklad/_progress.html.twig', $this->frame($firm, $job));
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    #[Route('/import/idoklad/{id}/connect', name: 'app_idoklad_connect', requirements: ['id' => Requirement::UUID_V7], methods: ['GET', 'POST'])]
    public function connect(string $id, Request $request): Response
    {
        $firm = $this->firm($id);
        $connection = $this->connections->for($firm);
        if (!$request->isMethod('POST')) {
            return $this->connectForm($firm, $connection?->clientId() ?? '', []);
        }
        $this->checkToken($request);
        $clientId = trim($request->request->getString('client_id'));
        $secret = $request->request->getString('client_secret');
        $errors = array_filter([
            'client_id' => '' === $clientId ? $this->translator->trans('idoklad.connect.error.client_id') : null,
            'client_secret' => '' === trim($secret) ? $this->translator->trans('idoklad.connect.error.client_secret') : null,
        ], static fn (?string $error): bool => null !== $error);
        if ([] === $errors) {
            try {
                $this->connect->connect($firm, $clientId, $secret);
            } catch (InvalidIdokladConnection) {
                $errors['client_id'] = $this->translator->trans('idoklad.connect.error.client_id');
            }
        }
        if ([] !== $errors) {
            return $this->connectForm($firm, $clientId, $errors, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->verify->verify($this->firm($id));
        } catch (IdokladFailure $failure) {
            // The keys stay, so the user may fix the tariff and try again without typing them.
            return $this->connectForm($this->firm($id), $clientId, ['form' => $this->translator->trans(IdokladJobRunner::errorOf($failure))], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->addFlash('success', $this->translator->trans('idoklad.connect.flash.connected'));

        return $this->redirectToRoute('app_idoklad_pull', ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    #[Route('/import/idoklad/{id}/disconnect', name: 'app_idoklad_disconnect', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function disconnect(string $id, Request $request): RedirectResponse
    {
        $firm = $this->firm($id);
        $this->checkToken($request);
        $this->connect->disconnect($firm);
        $this->addFlash('success', $this->translator->trans('idoklad.connect.flash.disconnected'));

        return $this->redirectToRoute('app_idoklad_connect', ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    #[Route('/import/idoklad/{id}/pull', name: 'app_idoklad_pull', requirements: ['id' => Requirement::UUID_V7], methods: ['GET'])]
    public function pull(string $id): Response
    {
        $firm = $this->firm($id);
        if ($this->isBusy($firm)) {
            return $this->toFirm($id);
        }
        $connection = $this->connections->for($firm);
        $job = $this->jobs->latest($firm);

        return $this->render('idoklad/pull.html.twig', [
            'firm' => $firm,
            'connection' => $connection,
            'pull' => $this->screens->pull($this->snapshot->pulls($firm), $this->now()),
            'canCheck' => $this->snapshot->lastSucceededPull($firm) instanceof IdokladPull,
            'jobError' => $job instanceof IdokladJob && $job->kind()->pulls() && IdokladJobState::Failed === $job->state() ? $this->translator->trans((string) $job->error()) : null,
            'quota' => self::MONTHLY_REQUESTS,
            'stepper' => $this->screens->stepper(1),
            'crumbs' => $this->screens->crumbs($firm),
        ]);
    }

    #[Route('/import/idoklad/{id}/pull', name: 'app_idoklad_pull_start', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function startPull(string $id, Request $request): RedirectResponse
    {
        $firm = $this->firm($id);
        $this->checkToken($request);
        if (!$this->connections->for($firm) instanceof IdokladConnection) {
            return $this->redirectToRoute('app_idoklad_connect', ['id' => $id], Response::HTTP_SEE_OTHER);
        }
        $changes = '1' === $request->request->getString('changes') && $this->snapshot->lastSucceededPull($firm) instanceof IdokladPull;
        $this->start->start($firm, $changes ? IdokladJobKind::Changes : IdokladJobKind::Pull);

        return $this->toFirm($id);
    }

    #[Route('/import/idoklad/{id}/check', name: 'app_idoklad_check', requirements: ['id' => Requirement::UUID_V7], methods: ['GET'])]
    public function check(string $id, Request $request): Response
    {
        $firm = $this->firm($id);
        if ($this->isBusy($firm)) {
            return $this->toFirm($id);
        }
        $check = $this->latestCheck($firm);
        if (!$check instanceof IdokladImportRun) {
            return $this->redirectToRoute('app_idoklad_pull', ['id' => $id]);
        }

        return $this->checkScreen($firm, $check, $request->getLocale(), $this->tenantClock->today());
    }

    #[Route('/import/idoklad/{id}/check', name: 'app_idoklad_check_start', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function startCheck(string $id, Request $request): RedirectResponse
    {
        $firm = $this->firm($id);
        $this->checkToken($request);
        if (!$this->snapshot->lastSucceededPull($firm) instanceof IdokladPull) {
            return $this->redirectToRoute('app_idoklad_pull', ['id' => $id], Response::HTTP_SEE_OTHER);
        }
        $this->start->start($firm, IdokladJobKind::Check);

        return $this->toFirm($id);
    }

    #[Route('/import/idoklad/{id}/run', name: 'app_idoklad_run_start', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function startRun(string $id, Request $request): Response
    {
        $firm = $this->firm($id);
        $this->checkToken($request);
        $check = $this->latestCheck($firm);
        if (!$check instanceof IdokladImportRun) {
            return $this->redirectToRoute('app_idoklad_pull', ['id' => $id], Response::HTTP_SEE_OTHER);
        }
        $typed = ['remind_from' => $request->request->getString('remind_from')];
        $remindFrom = $this->day($typed['remind_from']);
        // Empty: the firm was never a VAT payer. Left out of the form: the day of the snapshot.
        $chosen = $request->request->has('vat_payer_from');
        $payer = $request->request->getString('vat_payer_from');
        $payerFrom = $this->day($payer);
        $errors = array_filter([
            'remind_from' => $remindFrom instanceof \DateTimeImmutable ? null : 'idoklad.check.reminders.invalid',
            'vat_payer_from' => '' === $payer || $payerFrom instanceof \DateTimeImmutable ? null : 'idoklad.check.vat.invalid',
        ]);
        if ($chosen) {
            $typed['vat_payer_from'] = $payer;
        }
        if ([] !== $errors || !$remindFrom instanceof \DateTimeImmutable) {
            return $this->checkScreen($firm, $check, $request->getLocale(), $this->tenantClock->today(), $typed, $errors, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->start->start($firm, IdokladJobKind::Run, $remindFrom, $chosen ? new ImportOptions($payerFrom) : null);

        return $this->toFirm($id);
    }

    #[Route('/import/idoklad/{id}/runs/{run}', name: 'app_idoklad_run', requirements: ['id' => Requirement::UUID_V7, 'run' => Requirement::UUID_V7], methods: ['GET'])]
    public function run(string $id, string $run, Request $request): Response
    {
        $firm = $this->firm($id);

        return $this->runScreen($firm, $this->runOf($firm, $run), $request->getLocale());
    }

    #[Route('/import/idoklad/{id}/runs/{run}/undo', name: 'app_idoklad_undo', requirements: ['id' => Requirement::UUID_V7, 'run' => Requirement::UUID_V7], methods: ['POST'])]
    public function undo(string $id, string $run, Request $request): Response
    {
        $firm = $this->firm($id);
        $this->checkToken($request);
        $stored = $this->runOf($firm, $run);
        if (!UndoIdokladImport::isUndoable($stored, $this->now())) {
            $this->addFlash('error', $this->translator->trans('idoklad.undo.unavailable'));

            return $this->redirectToRoute('app_idoklad_run', ['id' => $id, 'run' => $run], Response::HTTP_SEE_OTHER);
        }
        $blockers = $this->undo->undo($stored);
        if ([] !== $blockers) {
            return $this->runScreen($firm, $this->runOf($firm, $run), $request->getLocale(), $blockers, Response::HTTP_CONFLICT);
        }
        $this->addFlash('success', $this->translator->trans('idoklad.undo.flash.done'));

        return $this->redirectToRoute('app_idoklad_run', ['id' => $id, 'run' => $run], Response::HTTP_SEE_OTHER);
    }

    #[Route('/import/idoklad/{id}/history', name: 'app_idoklad_history', requirements: ['id' => Requirement::UUID_V7], methods: ['GET'])]
    public function history(string $id): Response
    {
        $firm = $this->firm($id);

        return $this->render('idoklad/history.html.twig', [
            'firm' => $firm,
            'columns' => $this->screens->historyColumns(),
            'rows' => $this->screens->history($firm, $this->snapshot->pulls($firm), $this->runs->forFirm($firm)),
            'crumbs' => $this->screens->crumbs($firm, $this->translator->trans('idoklad.history.title')),
        ]);
    }

    /**
     * @param array<string, string> $errors field (client_id, client_secret, form) => message
     */
    private function connectForm(LegalEntity $firm, string $clientId, array $errors, int $status = Response::HTTP_OK): Response
    {
        return $this->render('idoklad/connect.html.twig', [
            'firm' => $firm,
            'connection' => $this->connections->for($firm),
            'clientId' => $clientId,
            'errors' => $errors,
            'stepper' => $this->screens->stepper(0),
            'crumbs' => $this->screens->crumbs($firm),
        ], new Response(status: $status));
    }

    /** A day as a date field sends it; null when it is none. */
    private function day(string $typed): ?\DateTimeImmutable
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $typed);

        return false !== $day && $day->format('Y-m-d') === $typed ? $day : null;
    }

    /**
     * @param array<string, string> $typed  what the form sent, by field
     * @param array<string, string> $errors field => translation key
     */
    private function checkScreen(LegalEntity $firm, IdokladImportRun $check, string $locale, \DateTimeImmutable $today, array $typed = [], array $errors = [], int $status = Response::HTTP_OK): Response
    {
        $job = $this->jobs->latest($firm);
        $error = fn (string $field): ?string => isset($errors[$field]) ? $this->translator->trans($errors[$field]) : null;

        return $this->render('idoklad/check.html.twig', [
            'firm' => $firm,
            'run' => $check,
            'report' => $this->screens->report($check, $locale, $today),
            'remindFrom' => $typed['remind_from'] ?? $today->format('Y-m-d'),
            'remindError' => $error('remind_from'),
            // The day the check ran with, which the snapshot gave unless the person chose it.
            'vatPayerFrom' => $typed['vat_payer_from'] ?? $check->options()?->vatPayerFrom?->format('Y-m-d') ?? '',
            'vatPayerError' => $error('vat_payer_from'),
            'jobError' => $job instanceof IdokladJob && !$job->kind()->pulls() && IdokladJobState::Failed === $job->state() ? $this->translator->trans((string) $job->error()) : null,
            'stale' => $this->snapshot->lastSucceededPull($firm)?->id() !== $check->pullId(),
            'stepper' => $this->screens->stepper(2),
            'crumbs' => $this->screens->crumbs($firm),
        ], new Response(status: $status));
    }

    /**
     * @param list<UndoBlocker> $blockers
     */
    private function runScreen(LegalEntity $firm, IdokladImportRun $run, string $locale, array $blockers = [], int $status = Response::HTTP_OK): Response
    {
        return $this->render('idoklad/run.html.twig', [
            'firm' => $firm,
            'run' => $run,
            'report' => $this->screens->report($run, $locale, $this->tenantClock->today()),
            'undoable' => UndoIdokladImport::isUndoable($run, $this->now()),
            'undoDeadline' => $run->startedAt()->modify('+30 days')->format($this->translator->trans('format.date')),
            'blockers' => array_map(fn (UndoBlocker $blocker): array => [
                'title' => '' !== $blocker->number ? $blocker->number : $this->translator->trans('idoklad.undo.payment'),
                'reason' => $this->translator->trans('idoklad.undo.blocker.'.$blocker->reason),
            ], $blockers),
            'stepper' => $this->screens->stepper(ImportMode::Run === $run->mode() ? 4 : 2),
            'crumbs' => $this->screens->crumbs($firm, $this->translator->trans('idoklad.run.mode.'.$run->mode()->value)),
        ], new Response(status: $status));
    }

    /**
     * @return array<string, mixed>
     */
    private function frame(LegalEntity $firm, IdokladJob $job): array
    {
        return [
            'firm' => $firm,
            'job' => $job,
            'badge' => $this->screens->jobBadge($job->state()),
            'pull' => $job->kind()->pulls() ? $this->screens->pull($this->snapshot->pulls($firm), $this->now()) : null,
        ];
    }

    private function latestCheck(LegalEntity $firm): ?IdokladImportRun
    {
        foreach ($this->runs->forFirm($firm) as $run) {
            if (ImportMode::Check === $run->mode()) {
                return $run;
            }
        }

        return null;
    }

    private function runOf(LegalEntity $firm, string $id): IdokladImportRun
    {
        $run = $this->runs->find($id);
        if (!$run instanceof IdokladImportRun || $run->firm()->id() !== $firm->id()) {
            throw new NotFoundHttpException();
        }

        return $run;
    }

    private function isBusy(LegalEntity $firm): bool
    {
        return $this->jobs->busy($firm) instanceof IdokladJob;
    }

    private function firm(string $id): LegalEntity
    {
        $this->denyUnlessOwner();
        try {
            return $this->firms->get($id);
        } catch (UnknownLegalEntity) {
            throw new NotFoundHttpException();
        }
    }

    private function denyUnlessOwner(): void
    {
        if (!$this->owner->isOwner()) {
            throw new AccessDeniedHttpException();
        }
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }

    private function toFirm(string $id): RedirectResponse
    {
        return $this->redirectToRoute('app_idoklad', ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
