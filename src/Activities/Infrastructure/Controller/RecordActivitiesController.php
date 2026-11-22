<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Infrastructure\Controller;

use Foresi\Activities\Application\ActivitiesRefused;
use Foresi\Activities\Application\ActivityReads;
use Foresi\Activities\Contract\RecordActivities;
use Foresi\Activities\Domain\Activity;
use Foresi\Activities\Infrastructure\Screen\ActivityScreen;
use Foresi\Attachment\Infrastructure\Controller\AttachmentController;
use Foresi\Shared\Application\RefusalKind;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * The timeline of one record (RecordActivities), shown inside another module's
 * screen such as a contact's Activities tab: what is planned on top with its
 * day, what is done below with its outcome and files, as HubSpot, Pipedrive and
 * Twenty show a record's activities, and "Log activity" and "Plan activity" in
 * a dialog with the record linked. Loaded by a Turbo frame it is that frame
 * alone; otherwise a page.
 */
final class RecordActivitiesController extends AbstractController
{
    public function __construct(private readonly ActivityReads $reads, private readonly ActivityScreen $screen)
    {
    }

    #[Route('/activities/for/{kind}/{id}', name: RecordActivities::ROUTE, requirements: ['kind' => '[a-z0-9_-]{1,40}', 'id' => Requirement::UUID], methods: ['GET'])]
    public function forRecord(string $kind, string $id, Request $request): Response
    {
        try {
            $activities = $this->reads->timeline($kind, $id);
        } catch (ActivitiesRefused $refused) {
            throw RefusalKind::Forbidden === $refused->kind() ? new AccessDeniedHttpException() : new NotFoundHttpException($refused->getMessage(), $refused);
        }
        $frame = $request->headers->get('Turbo-Frame');
        // The page the record is shown on, which the dialogs go back to.
        $return = AttachmentController::localPath($request->query->getString('return'))
            ?? $this->generateUrl(RecordActivities::ROUTE, ['kind' => $kind, 'id' => $id]);
        $items = fn (bool $done): array => array_values(array_map(
            fn (Activity $activity): array => $this->screen->item($activity, $kind, $id),
            array_filter($activities, static fn (Activity $activity): bool => $done === $activity->isDone()),
        ));

        return $this->render('@Activities/for_record.html.twig', [
            'frame' => \is_string($frame) && 1 === preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $frame) ? $frame : null,
            'kind' => $kind,
            'id' => $id,
            'return' => $return,
            'planned' => $items(false),
            'done' => $items(true),
        ]);
    }
}
