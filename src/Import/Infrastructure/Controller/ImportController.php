<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Infrastructure\Controller;

use App\Audit\Application\AuditActor;
use App\Import\Application\CancelImport;
use App\Import\Application\CheckImport;
use App\Import\Application\ConfigureImport;
use App\Import\Application\ImportErrorFile;
use App\Import\Application\ImportRefused;
use App\Import\Application\Imports;
use App\Import\Application\ProfileRepository;
use App\Import\Application\RunImport;
use App\Import\Application\Source\Encoding;
use App\Import\Application\Source\SourceFormat;
use App\Import\Application\Source\SourceSettings;
use App\Import\Application\StartImport;
use App\Import\Application\Target\ImportTarget;
use App\Import\Application\Target\TargetRegistry;
use App\Import\Application\UndoImport;
use App\Import\Domain\Import;
use App\Import\Domain\ImportState;
use App\Tenancy\Application\TenantContext;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The import screens: history, upload, and one page per import that shows the
 * step it is at (mapping, check, progress, summary). Every action goes through
 * the import's application services; an import of another tenant is never
 * found, so it answers 404 like one that does not exist.
 */
final class ImportController extends AbstractController
{
    public const string TOKEN = 'import_action';

    private const int MAX_UPLOAD_BYTES = 50 * 1_048_576;
    private const int HISTORY_LIMIT = 100;
    private const int MAX_HEADER_ROW = 50;

    public function __construct(
        private readonly StartImport $start,
        private readonly ConfigureImport $configure,
        private readonly CheckImport $checkImport,
        private readonly RunImport $runImport,
        private readonly UndoImport $undoImport,
        private readonly CancelImport $cancelImport,
        private readonly ImportErrorFile $errorFile,
        private readonly Imports $imports,
        private readonly ProfileRepository $profiles,
        private readonly TargetRegistry $targets,
        private readonly ImportScreens $screens,
        private readonly TenantContext $tenants,
        private readonly AuditActor $actor,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/imports', name: 'app_imports', methods: ['GET'])]
    public function index(): Response
    {
        $this->requireTenant();

        return $this->render('import/index.html.twig', [
            'columns' => $this->screens->historyColumns(),
            'rows' => $this->screens->historyRows($this->imports->history(self::HISTORY_LIMIT)),
            'newTarget' => $this->defaultTarget()?->key(),
        ]);
    }

    #[Route('/imports/new', name: 'app_import_new', methods: ['GET'])]
    public function new(Request $request): Response
    {
        $this->requireTenant();

        return $this->upload($this->chosenTarget($request->query->getString('target')));
    }

    #[Route('/imports', name: 'app_import_upload', methods: ['POST'])]
    public function store(Request $request): Response
    {
        $this->checkToken($request);
        $target = $this->chosenTarget($request->request->getString('target'));
        $file = $request->files->get('file');
        if (!$target instanceof ImportTarget) {
            return $this->upload(null, $this->translator->trans('import.upload.target_missing'), null, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $profile = $request->request->getString('profile');
        $profileId = ctype_digit($profile) ? (int) $profile : null;

        if (!$file instanceof UploadedFile || !$file->isValid()) {
            $error = $file instanceof UploadedFile && \in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true) ? 'import.upload.too_large' : 'import.upload.missing';

            return $this->upload($target, $this->translator->trans($error), $profileId, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($file->getSize() > self::MAX_UPLOAD_BYTES) {
            return $this->upload($target, $this->translator->trans('import.upload.too_large'), $profileId, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $id = $this->start->start($target->key(), $file->getPathname(), $file->getClientOriginalName(), $this->userId(), $profileId);
        } catch (ImportRefused $refused) {
            return $this->upload($target, $this->refusal($refused), null, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->toImport($id);
    }

    #[Route('/imports/{id}', name: 'app_import_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, Request $request): Response
    {
        $import = $this->import($id);
        $state = $import->state();
        $editingMapping = ImportState::Checked === $state && 'mapping' === $request->query->getString('step');

        if (ImportScreens::isBusy($state)) {
            return $this->screen('import/progress.html.twig', $import);
        }
        if (ImportState::Mapping === $state || $editingMapping) {
            return $this->screen('import/mapping.html.twig', $import, $this->screens->mapping($import, editSource: ImportState::Mapping === $state && 'edit' === $request->query->getString('source')), $editingMapping);
        }
        if (ImportState::Checked === $state) {
            return $this->screen('import/check.html.twig', $import, $this->screens->check($import, $request->query->getString('outcome')));
        }

        // Opening the summary of one's own import that ended answers its notice.
        if (!$import->seenAt() instanceof \DateTimeImmutable && $import->actorUserId() === $this->actor->userId()) {
            $import->markSeen(\DateTimeImmutable::createFromInterface($this->clock->now()));
            $this->imports->save();
        }

        return $this->screen('import/summary.html.twig', $import, $this->screens->summary($import, $request->getLocale()));
    }

    #[Route('/imports/{id}/progress', name: 'app_import_progress', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function progress(int $id): Response
    {
        $import = $this->import($id);
        $response = $this->render('import/_progress.html.twig', ['import' => $import, 'progress' => $this->screens->progress($import)]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    #[Route('/imports/{id}/mapping', name: 'app_import_mapping', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function mapping(int $id, Request $request): Response
    {
        $this->checkToken($request);
        $import = $this->import($id);
        $submission = MappingSubmission::read($request, $import);

        try {
            $this->configure->configure($id, $submission->mapping, $submission->options, $submission->profileId, null === $submission->profileId ? $submission->profileName : null);
            $this->checkImport->check($id);
        } catch (ImportRefused $refused) {
            $import = $this->import($id);
            if (!\in_array($import->state(), [ImportState::Mapping, ImportState::Checked], true)) {
                return $this->refused($id, $refused);
            }

            return $this->screen(
                'import/mapping.html.twig',
                $import,
                $this->screens->mapping($import, $submission->mapping, $submission->options, $submission->profileName),
                ImportState::Checked === $import->state(),
                ['error' => $this->refusal($refused, $import->targetKey())],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return $this->toImport($id);
    }

    #[Route('/imports/{id}/source', name: 'app_import_source', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function source(int $id, Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $import = $this->import($id);
        $current = SourceSettings::fromArray($import->sourceSettings());
        $form = $request->request;
        $headerRow = $form->getInt('header_row');
        $delimiter = $form->getString('delimiter');
        $sheet = trim($form->getString('sheet'));
        $settings = new SourceSettings(
            $current->format,
            SourceFormat::Csv === $current->format ? Encoding::tryFrom($form->getString('encoding')) : null,
            SourceFormat::Csv === $current->format && 1 === mb_strlen($delimiter) ? $delimiter : null,
            SourceFormat::Xlsx === $current->format && '' !== $sheet ? $sheet : $current->sheet,
            $headerRow >= 1 && $headerRow <= self::MAX_HEADER_ROW ? $headerRow : null,
        );

        try {
            $this->configure->reread($id, $settings);
        } catch (ImportRefused $refused) {
            return $this->refused($id, $refused);
        }

        return $this->toImport($id);
    }

    #[Route('/imports/{id}/check', name: 'app_import_check', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function check(int $id, Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $this->import($id);

        try {
            $this->checkImport->check($id);
        } catch (ImportRefused $refused) {
            return $this->refused($id, $refused);
        }

        return $this->toImport($id);
    }

    #[Route('/imports/{id}/run', name: 'app_import_run', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function run(int $id, Request $request): Response
    {
        $this->checkToken($request);
        $import = $this->import($id);
        $typed = preg_replace('/\D+/', '', $request->request->getString('confirmation')) ?? '';

        try {
            $this->runImport->run($id, '' === $typed ? null : (int) mb_substr($typed, 0, 9));
        } catch (ImportRefused $refused) {
            if ('import.refused.archive_not_confirmed' === $refused->reason && ImportState::Checked === $import->state()) {
                $import = $this->import($id);

                return $this->screen('import/check.html.twig', $import, $this->screens->check($import, null), false, ['confirmationError' => $this->refusal($refused)], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return $this->refused($id, $refused);
        }

        return $this->toImport($id);
    }

    #[Route('/imports/{id}/cancel', name: 'app_import_cancel', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function cancel(int $id, Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $this->import($id);

        try {
            $this->cancelImport->cancel($id);
        } catch (ImportRefused $refused) {
            return $this->refused($id, $refused);
        }
        // Whoever cancels knows it ended; no notice is needed for it.
        $import = $this->import($id);
        if ($import->actorUserId() === $this->actor->userId()) {
            $import->markSeen(\DateTimeImmutable::createFromInterface($this->clock->now()));
            $this->imports->save();
        }
        $this->addFlash('success', $this->translator->trans('import.flash.cancelled'));

        return $this->toImport($id);
    }

    #[Route('/imports/{id}/undo', name: 'app_import_undo', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function undo(int $id, Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $this->import($id);

        try {
            $this->undoImport->undo($id);
        } catch (ImportRefused $refused) {
            return $this->refused($id, $refused);
        }

        return $this->toImport($id);
    }

    /**
     * The rows that failed, in the format of the uploaded file. The file name
     * carries the import's number only, never the name of the uploaded file.
     */
    #[Route('/imports/{id}/errors', name: 'app_import_errors', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function errors(int $id, Request $request): Response
    {
        $this->import($id);
        $path = tempnam(sys_get_temp_dir(), 'foresi-import-errors-');
        if (false === $path) {
            throw new \RuntimeException('No temporary file for the error file.');
        }

        try {
            $format = $this->errorFile->write($id, $request->getLocale(), $path);
        } catch (ImportRefused $refused) {
            @unlink($path);

            return $this->refused($id, $refused);
        }

        $response = new BinaryFileResponse($path);
        $response->deleteFileAfterSend();
        $response->headers->set('Content-Type', SourceFormat::Xlsx === $format ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, \sprintf('import-%d-errors.%s', $id, $format->value)));
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $extra
     */
    private function screen(string $template, Import $import, array $data = [], bool $editingMapping = false, array $extra = [], int $status = Response::HTTP_OK): Response
    {
        $response = $this->render($template, [
            'import' => $import,
            'screen' => $data,
            'stepper' => $this->screens->stepper($import, $editingMapping),
            'crumbs' => $this->screens->crumbs($import->targetKey(), $import),
            'badge' => $this->screens->stateBadge($import->state()),
            'progress' => ImportScreens::isBusy($import->state()) ? $this->screens->progress($import) : null,
            'targetLabel' => $this->screens->targetLabel($import->targetKey()),
            'editingMapping' => $editingMapping,
        ] + $extra);
        $response->setStatusCode($status);

        return $response;
    }

    /**
     * @param ImportTarget|null $target null lets the user choose among all targets
     */
    private function upload(?ImportTarget $target, ?string $error = null, ?int $profileId = null, int $status = Response::HTTP_OK): Response
    {
        $profiles = [];
        foreach ($target instanceof ImportTarget ? $this->profiles->forTarget($target->key()) : [] as $profile) {
            $profiles[''] ??= $this->translator->trans('import.upload.profile_none');
            $profiles[(string) $profile->id()] = $profile->name();
        }
        $targets = [];
        foreach ($target instanceof ImportTarget ? [] : $this->targets->all() as $candidate) {
            $targets[''] ??= $this->translator->trans('import.upload.target_choose');
            $targets[$candidate->key()] = $this->screens->targetLabel($candidate->key());
        }
        $response = $this->render('import/upload.html.twig', [
            'target' => $target?->key(),
            'targets' => $targets,
            'profiles' => $profiles,
            'profileId' => null === $profileId ? '' : (string) $profileId,
            'error' => $error,
            'stepper' => ['steps' => $this->screens->steps(), 'current' => 0],
            'crumbs' => $target instanceof ImportTarget ? $this->screens->crumbs($target->key()) : [['label' => $this->translator->trans('import.history.title'), 'href' => $this->generateUrl('app_imports')], ['label' => $this->translator->trans('import.upload.title')]],
        ]);
        $response->setStatusCode($status);

        return $response;
    }

    /** Null when none is named and there is more than one to choose from. */
    private function chosenTarget(string $key): ?ImportTarget
    {
        if ('' === $key) {
            return $this->defaultTarget();
        }
        try {
            return $this->targets->get($key);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException();
        }
    }

    /** The target a new import goes into when none is named: the only one, if there is one. */
    private function defaultTarget(): ?ImportTarget
    {
        $all = $this->targets->all();

        return 1 === \count($all) ? $all[0] : null;
    }

    private function import(int $id): Import
    {
        $this->requireTenant();

        // Fresh: a long-running process (a worker in the same process, a test) may hold an older copy.
        return $this->imports->findFresh($id) ?? throw new NotFoundHttpException();
    }

    private function requireTenant(): void
    {
        if (!$this->tenants->has()) {
            throw new NotFoundHttpException();
        }
    }

    private function userId(): int
    {
        return $this->actor->userId() ?? throw new AccessDeniedHttpException();
    }

    private function checkToken(Request $request): void
    {
        $this->requireTenant();
        if (!$this->isCsrfTokenValid(self::TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }

    /**
     * @param string|null $targetKey names the fields of a refusal by their labels, not their keys
     */
    private function refusal(ImportRefused $refused, ?string $targetKey = null): string
    {
        $parameters = $refused->parameters;
        if (null !== $targetKey && isset($parameters['fields'])) {
            $parameters['fields'] = implode(', ', $this->screens->fieldLabels($targetKey, explode(', ', (string) $parameters['fields'])));
        }

        return $this->translator->trans($refused->reason, $parameters);
    }

    private function refused(int $id, ImportRefused $refused): RedirectResponse
    {
        if ('import.refused.not_found' === $refused->reason) {
            throw new NotFoundHttpException();
        }
        $this->addFlash('error', $this->refusal($refused));

        return $this->toImport($id);
    }

    private function toImport(int $id): RedirectResponse
    {
        return $this->redirectToRoute('app_import_show', ['id' => $id], Response::HTTP_SEE_OTHER);
    }
}
