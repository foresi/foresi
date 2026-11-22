<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Infrastructure\Controller;

use Foresi\Activities\Application\ActivitiesAccess;
use Foresi\Activities\Application\ActivitiesRefused;
use Foresi\Activities\Application\ActivityReads;
use Foresi\Activities\Application\ActivityTargets;
use Foresi\Activities\Application\ActivityTimes;
use Foresi\Activities\Application\ActivityWrites;
use Foresi\Activities\Application\Api\CompleteActivityInput;
use Foresi\Activities\Application\Api\CreateActivityInput;
use Foresi\Activities\Application\Api\RestoreActivityInput;
use Foresi\Activities\Application\Api\TrashActivityInput;
use Foresi\Activities\Application\Api\UpdateActivityInput;
use Foresi\Activities\Application\RecordLink;
use Foresi\Activities\Domain\Activity;
use Foresi\Activities\Domain\ActivityLink;
use Foresi\Activities\Domain\ActivityTiming;
use Foresi\Activities\Infrastructure\Screen\ActivityScreen;
use Foresi\Attachment\Application\AttachmentRefused;
use Foresi\Attachment\Application\AttachmentUpload;
use Foresi\Attachment\Application\UploadAttachment;
use Foresi\Attachment\Infrastructure\Controller\AttachmentController;
use Foresi\Shared\Application\RefusalKind;
use Foresi\Shared\Domain\Id;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Activities on screen. "Log activity" and "Plan activity" open a dialog over
 * the record they come from, with the record linked; the type decides which
 * time fields show (a time and a duration, a day, or none). An activity is a
 * record of its own, in the side sheet over the timeline, with its notes and
 * files; its header has "Done and plan next" while it is planned, which marks
 * it done and plans the next step in one dialog, as Odoo's "Done & Schedule
 * Next" and Pipedrive's follow-up prompt do, then Edit and Delete. Delete asks
 * nothing: the message says so with its Undo. For the owners and members only.
 */
final class ActivityController extends AbstractController
{
    public const string TOKEN = 'activities';
    public const string SHOW_ROUTE = 'app_activity';
    public const string NEW_ROUTE = 'app_activity_new';
    public const string UNDO_FLASH = 'activities_undo';

    /** The fields of the activity form, as the use cases name them. */
    private const array FIELDS = ['typeId', 'subject', 'body', 'plannedOn', 'plannedAt', 'durationMinutes', 'doneAt', 'outcome', 'links'];

    public function __construct(
        private readonly ActivitiesAccess $access,
        private readonly ActivityReads $reads,
        private readonly ActivityWrites $writes,
        private readonly ActivityTargets $targets,
        private readonly ActivityScreen $screen,
        private readonly ActivityTimes $times,
        private readonly UploadAttachment $upload,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Logs a done activity (?mode=log) or plans one (?mode=plan), linked to the
     * record it was opened from (?link_kind=contact&link_id=...), with a file.
     */
    #[Route('/activities/new', name: self::NEW_ROUTE, methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->denyUnlessStaff();
        $source = $request->isMethod('POST') ? $request->request : $request->query;
        $log = 'log' === $source->getString('mode');
        $link = $this->link($source->getString('link_kind'), $source->getString('link_id'));
        $return = AttachmentController::localPath($source->getString('return'));
        if (!$request->isMethod('POST')) {
            $type = $log ? $this->screen->firstOf(ActivityTiming::Timed, ActivityTiming::None) : $this->screen->firstOf(ActivityTiming::Timed, ActivityTiming::Date);
            $values = ['typeId' => $type?->id() ?? '', 'subject' => '', 'body' => '', 'plannedOn' => '', 'plannedAt' => '', 'durationMinutes' => '', 'doneAt' => $log ? $this->screen->field($this->now()) : '', 'outcome' => ''];

            return $this->form($log ? 'log' : 'plan', null, $values, [], $link, $return);
        }

        $this->checkToken($request);
        $values = $this->values($request);
        try {
            $activity = $this->writes->create(new CreateActivityInput(
                $values['typeId'],
                $values['subject'],
                $values['body'],
                $log ? null : $this->text($values['plannedOn']),
                $log ? null : $this->text($values['plannedAt']),
                $this->number($values['durationMinutes']),
                $log,
                $log ? $this->text($values['doneAt']) : null,
                $log ? $this->text($values['outcome']) : null,
                $link instanceof RecordLink ? [['kind' => $link->kind, 'id' => $link->id]] : [],
            ));
        } catch (ActivitiesRefused $refused) {
            return $this->form($log ? 'log' : 'plan', null, $values, $this->errors($refused), $link, $return);
        }
        $this->attach($request, $activity);
        $this->addFlash('success', $this->translator->trans($log ? 'activities.flash.logged' : 'activities.flash.planned', ['subject' => $activity->subject()]));

        return $this->back($return, $activity);
    }

    #[Route('/activities/{id}', name: self::SHOW_ROUTE, requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function show(string $id): Response
    {
        $activity = $this->activity($id);
        $item = $this->screen->item($activity);

        return $this->render('@Activities/show.html.twig', [
            'activity' => $activity,
            'item' => $item,
            'attachmentKind' => Activity::ATTACHMENT_KIND,
        ]);
    }

    #[Route('/activities/{id}/edit', name: 'app_activity_edit', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request): Response
    {
        $activity = $this->activity($id);
        $return = AttachmentController::localPath(($request->isMethod('POST') ? $request->request : $request->query)->getString('return'));
        if (!$request->isMethod('POST')) {
            return $this->form('edit', $activity, [
                'typeId' => $activity->typeId(),
                'subject' => $activity->subject(),
                'body' => $activity->body(),
                'plannedOn' => $activity->plannedOn()?->format('Y-m-d') ?? '',
                'plannedAt' => $this->screen->field($activity->plannedAt()),
                'durationMinutes' => (string) $activity->durationMinutes(),
                'doneAt' => $this->screen->field($activity->doneAt()),
                'outcome' => $activity->outcome() ?? '',
            ], [], null, $return);
        }

        $this->checkToken($request);
        $values = $this->values($request);
        try {
            $this->writes->update(new UpdateActivityInput(
                $activity->id(),
                typeId: $values['typeId'],
                subject: $values['subject'],
                body: $values['body'],
                plannedOn: $this->text($values['plannedOn']),
                plannedAt: $this->text($values['plannedAt']),
                durationMinutes: $this->number($values['durationMinutes']),
                outcome: $this->text($values['outcome']),
            ));
        } catch (ActivitiesRefused $refused) {
            $this->denyIfForbidden($refused);

            return $this->form('edit', $activity, $values, $this->errors($refused), null, $return);
        }
        $this->attach($request, $activity);
        $this->addFlash('success', $this->translator->trans('activities.flash.saved', ['subject' => $activity->subject()]));

        return $this->back($return, $activity);
    }

    /**
     * "Done and plan next": what came of it, and the next step on the same
     * records, planned in the same dialog.
     */
    #[Route('/activities/{id}/complete', name: 'app_activity_complete', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function complete(string $id, Request $request): Response
    {
        $activity = $this->activity($id);
        $return = AttachmentController::localPath(($request->isMethod('POST') ? $request->request : $request->query)->getString('return'));
        $next = $this->screen->firstOf(ActivityTiming::Timed, ActivityTiming::Date);
        $values = ['doneAt' => $this->screen->field($this->now()), 'outcome' => '', 'planNext' => true, 'nextTypeId' => $next?->id() ?? '', 'nextSubject' => '', 'nextPlannedOn' => '', 'nextPlannedAt' => '', 'nextDurationMinutes' => ''];
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            $values = ['planNext' => $request->request->getBoolean('planNext')];
            foreach (['doneAt', 'outcome', 'nextTypeId', 'nextSubject', 'nextPlannedOn', 'nextPlannedAt', 'nextDurationMinutes'] as $field) {
                $values[$field] = trim($request->request->getString($field));
            }
            $planNext = $values['planNext'];
            try {
                [, $planned] = $this->writes->complete(new CompleteActivityInput(
                    $activity->id(),
                    $this->text($values['doneAt']),
                    $this->text($values['outcome']),
                    $planNext ? $values['nextTypeId'] : null,
                    $planNext ? $values['nextSubject'] : null,
                    $planNext ? $this->text($values['nextPlannedOn']) : null,
                    $planNext ? $this->text($values['nextPlannedAt']) : null,
                    $planNext ? $this->number($values['nextDurationMinutes']) : null,
                ));
                $this->addFlash('success', $this->translator->trans($planned instanceof Activity ? 'activities.flash.done_and_planned' : 'activities.flash.done', ['subject' => $activity->subject()]));

                return $this->back($return, $activity);
            } catch (ActivitiesRefused $refused) {
                $this->denyIfForbidden($refused);
                $field = $refused->field();
                $errors = [\in_array($field, ['doneAt', 'outcome', 'nextTypeId', 'nextSubject', 'nextPlannedOn', 'nextPlannedAt', 'nextDurationMinutes'], true) ? $field : 'outcome' => $this->screen->refusal($refused)];
            }
        }

        return $this->render('@Activities/complete.html.twig', [
            'activity' => $activity,
            'item' => $this->screen->item($activity),
            'values' => $values,
            'errors' => $errors,
            'types' => $this->screen->typeOptions(),
            'timings' => $this->screen->timings(),
            'return' => $return,
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/activities/{id}/trash', name: 'app_activity_trash', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function trash(string $id, Request $request): RedirectResponse
    {
        $this->denyUnlessStaff();
        $this->checkToken($request);
        $return = AttachmentController::localPath($request->request->getString('return'));
        try {
            $activity = $this->writes->trash(new TrashActivityInput($id));
        } catch (ActivitiesRefused $refused) {
            throw $this->notFoundOrDenied($refused);
        }
        $this->addFlash(self::UNDO_FLASH, [
            'message' => $this->translator->trans('activities.flash.trashed', ['subject' => $activity->subject()]),
            'label' => $this->translator->trans('activities.action.undo'),
            'href' => $this->generateUrl('app_activity_restore', ['id' => $id, 'return' => $return]),
            'token' => self::TOKEN,
        ]);

        return new RedirectResponse($return ?? '/', Response::HTTP_SEE_OTHER);
    }

    #[Route('/activities/{id}/restore', name: 'app_activity_restore', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function restore(string $id, Request $request): RedirectResponse
    {
        $this->denyUnlessStaff();
        $this->checkToken($request);
        try {
            $activity = $this->writes->restore(new RestoreActivityInput($id));
        } catch (ActivitiesRefused $refused) {
            throw $this->notFoundOrDenied($refused);
        }
        $this->addFlash('success', $this->translator->trans('activities.flash.restored', ['subject' => $activity->subject()]));
        $return = AttachmentController::localPath($request->query->getString('return'));

        return null === $return ? $this->redirectToRoute(self::SHOW_ROUTE, ['id' => $id], Response::HTTP_SEE_OTHER) : new RedirectResponse($return, Response::HTTP_SEE_OTHER);
    }

    /**
     * @param array<string, string> $values
     * @param array<string, string> $errors
     */
    private function form(string $mode, ?Activity $activity, array $values, array $errors, ?RecordLink $link, ?string $return): Response
    {
        return $this->render('@Activities/form.html.twig', [
            'mode' => $mode,
            'activity' => $activity,
            'values' => $values,
            'errors' => $errors,
            'link' => $link,
            'linkView' => $link instanceof RecordLink ? $this->targets->describe($link) : null,
            'types' => $this->screen->typeOptions($activity?->typeId()),
            'timings' => $this->screen->timings(),
            'return' => $return,
            'action' => $activity instanceof Activity ? $this->generateUrl('app_activity_edit', ['id' => $activity->id()]) : $this->generateUrl(self::NEW_ROUTE),
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /**
     * The file chosen in the form, attached once the activity is saved. A file
     * that is refused leaves the activity as it is and says why.
     */
    private function attach(Request $request, Activity $activity): void
    {
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            return;
        }
        try {
            $this->upload->upload(Activity::ATTACHMENT_KIND, $activity->id(), new AttachmentUpload(
                $file->getPathname(),
                $file->getClientOriginalName(),
                $file->isValid() ? (int) $file->getSize() : 0,
                $file->isValid(),
                \in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true),
            ));
        } catch (AttachmentRefused $refused) {
            $this->addFlash('error', $this->translator->trans('attachment.error.'.$refused->reason));
        }
    }

    /**
     * @return array<string, string>
     */
    private function values(Request $request): array
    {
        $values = [];
        foreach (self::FIELDS as $field) {
            $values[$field] = 'body' === $field ? $request->request->getString($field) : trim($request->request->getString($field));
        }
        unset($values['links']);

        return $values;
    }

    /**
     * @return array<string, string>
     */
    private function errors(ActivitiesRefused $refused): array
    {
        $this->denyIfForbidden($refused);
        $field = $refused->field();

        return [\in_array($field, self::FIELDS, true) ? $field : 'subject' => $this->screen->refusal($refused)];
    }

    private function back(?string $return, Activity $activity): RedirectResponse
    {
        return null === $return ? $this->redirectToRoute(self::SHOW_ROUTE, ['id' => $activity->id()], Response::HTTP_SEE_OTHER) : new RedirectResponse($return, Response::HTTP_SEE_OTHER);
    }

    private function activity(string $id): Activity
    {
        $this->denyUnlessStaff();
        try {
            return $this->reads->activity($id);
        } catch (ActivitiesRefused $refused) {
            throw $this->notFoundOrDenied($refused);
        }
    }

    private function link(string $kind, string $id): ?RecordLink
    {
        $kind = trim($kind);
        if ('' === $kind || \strlen($kind) > ActivityLink::MAX_KIND || 1 !== preg_match('/^[a-z][a-z0-9_]*$/', $kind) || !Id::isValid($id)) {
            return null;
        }

        return new RecordLink($kind, $id);
    }

    private function text(string $value): ?string
    {
        return '' === $value ? null : $value;
    }

    private function number(string $value): ?int
    {
        return 1 === preg_match('/^\d{1,5}$/', $value) ? (int) $value : null;
    }

    private function now(): \DateTimeImmutable
    {
        return $this->times->now();
    }

    private function notFoundOrDenied(ActivitiesRefused $refused): \Throwable
    {
        return RefusalKind::Forbidden === $refused->kind() ? new AccessDeniedHttpException() : new NotFoundHttpException($refused->getMessage(), $refused);
    }

    private function denyUnlessStaff(): void
    {
        try {
            $this->access->denyUnlessStaff();
        } catch (ActivitiesRefused) {
            throw new AccessDeniedHttpException();
        }
    }

    private function denyIfForbidden(ActivitiesRefused $refused): void
    {
        if (RefusalKind::Forbidden === $refused->kind()) {
            throw new AccessDeniedHttpException();
        }
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
