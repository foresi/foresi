<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Infrastructure\Controller;

use Foresi\Instance\Application\InstanceClock;
use Foresi\Shared\Application\RefusalKind;
use Foresi\TaxesAndContributions\Application\Estimates;
use Foresi\TaxesAndContributions\Application\ProfileReads;
use Foresi\TaxesAndContributions\Application\TaxesAccess;
use Foresi\TaxesAndContributions\Application\TaxesRefused;
use Foresi\TaxesAndContributions\Contract\EstimateView;
use Foresi\TaxesAndContributions\Contract\ExpenseMethod;
use Foresi\TaxesAndContributions\Infrastructure\Screen\TaxesScreen;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The page Money → Taxes and contributions: the year as tabs (last, this and
 * next), what the owner keeps to date beside the year end, the expense
 * methods side by side, the credits, and the steps behind every number in the
 * side sheet (docs/design/overlays.md). Only the owner sees it (B7): a member
 * is refused with 403.
 */
final class TaxesController extends AbstractController
{
    public const string ROUTE = 'app_taxes';

    public function __construct(
        private readonly Estimates $estimates,
        private readonly ProfileReads $profiles,
        private readonly TaxesScreen $screen,
        private readonly TaxesAccess $access,
        private readonly InstanceClock $clock,
    ) {
    }

    #[Route('/taxes-and-contributions', name: self::ROUTE, methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyUnlessOwner();
        $thisYear = (int) $this->clock->today()->format('Y');
        $year = $request->query->getString('year', (string) $thisYear);
        if (1 !== preg_match('/^[0-9]{1,4}$/', $year)) {
            throw new NotFoundHttpException();
        }
        $year = (int) $year;
        $estimate = $this->estimate($year);

        return $this->render('@TaxesAndContributions/taxes/index.html.twig', [
            'year' => $year,
            'tabs' => $this->screen->tabs($year, $thisYear),
            'reason' => $estimate->toDateReason,
            'yearEndReason' => $estimate->yearEndReason,
            'summary' => $this->screen->summary($estimate, $this->profiles->year($year)->chosenMethod),
            'comparison' => $this->screen->comparison($estimate),
            'credits' => $this->screen->credits($estimate),
            'warnings' => $this->screen->notes('warning', $estimate->warnings),
            'footer' => $this->screen->footer($estimate),
        ]);
    }

    /**
     * The steps behind one number of the page, in the side sheet: each with
     * its input, its rule, where the rule is published and its result. The
     * same URL is a page.
     */
    #[Route('/taxes-and-contributions/steps/{year}/{method}/{step}', name: self::ROUTE.'_steps', requirements: ['year' => '[0-9]{1,4}', 'method' => '[a-z0-9_]{1,20}', 'step' => '[a-z0-9_]{1,20}'], methods: ['GET'])]
    public function steps(int $year, string $method, string $step): Response
    {
        $this->denyUnlessOwner();
        $keys = TaxesScreen::CHAINS[$step] ?? throw new NotFoundHttpException();
        $expenseMethod = ExpenseMethod::tryFrom($method) ?? throw new NotFoundHttpException();
        $toDate = $this->estimate($year)->toDate ?? throw new NotFoundHttpException();
        try {
            $result = $toDate->method($expenseMethod);
        } catch (\OutOfBoundsException $missing) {
            throw new NotFoundHttpException($missing->getMessage(), $missing);
        }

        return $this->render('@TaxesAndContributions/taxes/steps.html.twig', [
            'year' => $year,
            'title' => $this->screen->stepsTitle($step, $expenseMethod),
            'steps' => $this->screen->steps($result, $keys),
        ]);
    }

    private function estimate(int $year): EstimateView
    {
        try {
            return $this->estimates->of($year);
        } catch (TaxesRefused $refused) {
            if (RefusalKind::Forbidden === $refused->kind()) {
                throw new AccessDeniedHttpException($refused->getMessage(), $refused);
            }
            if ('invalid_year' === $refused->reason()) {
                throw new NotFoundHttpException($refused->getMessage(), $refused);
            }

            throw $refused;
        }
    }

    private function denyUnlessOwner(): void
    {
        if (!$this->access->isOwner()) {
            throw new AccessDeniedHttpException('Only the owner of the organisation sees the taxes and contributions.');
        }
    }
}
