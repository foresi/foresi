<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Timesheet\Infrastructure\Controller;

use Foresi\Identity\Application\OwnerCheck;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Infrastructure\Http\TypedAmount;
use Foresi\Timesheet\Application\ProjectTimeSettings;
use Foresi\Timesheet\Application\TimeRefused;
use Foresi\Work\Application\WorkRefused;
use Foresi\Work\Infrastructure\Controller\ProjectSettingsController;
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
 * The time card of a project's settings, for an owner only: the project's own
 * hourly rate, a plain form that comes back to the settings, where a refusal
 * is told. An empty rate takes the project's own away.
 */
final class ProjectTimeSettingsController extends AbstractController
{
    public const string ROUTE = 'app_project_time_settings';
    public const string TOKEN = 'timesheet_project_settings';

    public function __construct(
        private readonly ProjectTimeSettings $settings,
        private readonly OwnerCheck $owner,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/projects/{id}/time-settings', name: self::ROUTE, requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function save(string $id, Request $request): RedirectResponse
    {
        if (!$this->owner->isOwner()) {
            throw new AccessDeniedHttpException('Only an owner sets the hourly rate of a project.');
        }
        if (!$this->isCsrfTokenValid(self::TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
        $currency = Currency::tryFrom($request->request->getString('rateCurrency'));
        $rate = $currency instanceof Currency ? TypedAmount::read(trim($request->request->getString('rate')), $currency, $request->getLocale()) : false;
        if (false === $rate) {
            $this->addFlash('error', $this->translator->trans('timesheet.project.error.rate'));

            return $this->back($id);
        }
        try {
            $this->settings->setHourlyRate($id, $rate);
            $this->addFlash('success', $this->translator->trans('timesheet.project.settings.saved'));
        } catch (TimeRefused|WorkRefused $refused) {
            if ('unknown' === $refused->reason) {
                throw new NotFoundHttpException($refused->getMessage(), $refused);
            }
            $this->addFlash('error', $this->translator->trans(TimeRefused::translationKey($refused)));
        } catch (\DomainException) {
            $this->addFlash('error', $this->translator->trans('timesheet.project.error.rate'));
        }

        return $this->back($id);
    }

    private function back(string $id): RedirectResponse
    {
        return $this->redirectToRoute(ProjectSettingsController::ROUTE, ['id' => $id], Response::HTTP_SEE_OTHER);
    }
}
