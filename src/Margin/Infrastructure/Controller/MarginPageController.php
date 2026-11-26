<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Infrastructure\Controller;

use Foresi\Margin\Application\MarginAccess;
use Foresi\Margin\Application\MarginCell;
use Foresi\Margin\Application\MarginRanges;
use Foresi\Margin\Application\MarginTable;
use Foresi\Margin\Domain\MarginRefused;
use Foresi\Margin\Infrastructure\Screen\MarginScreen;
use Foresi\Shared\Contract\RefusalKind;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The Margin page under Money: revenue, costs and margin per client and month,
 * from MarginTable, the range as a small dialog from the header, and the sheet
 * over one cell from MarginCell (docs/design/overlays.md). Only the owners and
 * members of the organisation see it; a guest is refused with 403, as on the
 * Cash flow and VAT pages.
 */
final class MarginPageController extends AbstractController
{
    public const string ROUTE = 'app_margin';
    public const string CELL_ROUTE = 'app_margin_cell';
    public const string RANGE_ROUTE = 'app_margin_range';
    public const string RANGE_TOKEN = 'margin_range';

    public function __construct(
        private readonly MarginTable $table,
        private readonly MarginCell $cell,
        private readonly MarginRanges $ranges,
        private readonly MarginAccess $access,
        private readonly MarginScreen $screen,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/margin', name: self::ROUTE, methods: ['GET'])]
    public function index(Request $request): Response
    {
        try {
            $table = $this->table->of($this->month($request, 'from'), $this->month($request, 'to'));
        } catch (MarginRefused $refused) {
            if (RefusalKind::Invalid !== $refused->kind()) {
                throw $this->denied($refused);
            }
            // A range written into the address by hand that cannot be used: say so, and show the default one.
            $this->addFlash('error', $this->translator->trans('margin.refused.'.$refused->reason()));

            return $this->redirectToRoute(self::ROUTE, [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('@Margin/page/index.html.twig', ['page' => $this->screen->table($table), 'from' => $table->from, 'to' => $table->to]);
    }

    #[Route('/margin/cell', name: self::CELL_ROUTE, methods: ['GET'])]
    public function cell(Request $request): Response
    {
        try {
            $view = $this->cell->of($request->query->getString('row'), $request->query->getString('month'));
        } catch (MarginRefused $refused) {
            throw $this->denied($refused);
        }

        return $this->render('@Margin/page/cell.html.twig', ['sheet' => $this->screen->cell($view)]);
    }

    #[Route('/margin/range', name: self::RANGE_ROUTE, methods: ['GET', 'POST'])]
    public function range(Request $request): Response
    {
        try {
            $this->access->denyUnlessStaff();
        } catch (MarginRefused $refused) {
            throw $this->denied($refused);
        }
        $values = ['from' => $this->month($request, 'from') ?? '', 'to' => $this->month($request, 'to') ?? ''];
        $error = null;
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid(self::RANGE_TOKEN, $request->request->getString('_token'))) {
                throw new AccessDeniedHttpException('Invalid CSRF token.');
            }
            $values = ['from' => trim($request->request->getString('from')), 'to' => trim($request->request->getString('to'))];
            try {
                // The same checks as the table and the API: a month, in order, at most 24 of them.
                $range = $this->ranges->range($values['from'], $values['to']);

                return $this->redirectToRoute(self::ROUTE, ['from' => $range->fromMonth(), 'to' => $range->toMonth()], Response::HTTP_SEE_OTHER);
            } catch (MarginRefused $refused) {
                $error = $this->translator->trans('margin.refused.'.$refused->reason());
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            }
        }
        if ('' === $values['from'] || '' === $values['to']) {
            $default = $this->ranges->range();
            $values = ['from' => '' === $values['from'] ? $default->fromMonth() : $values['from'], 'to' => '' === $values['to'] ? $default->toMonth() : $values['to']];
        }

        return $this->render('@Margin/page/range.html.twig', ['values' => $values, 'error' => $error], new Response(status: $status));
    }

    private function month(Request $request, string $name): ?string
    {
        $value = trim($request->query->getString($name));

        return '' === $value ? null : $value;
    }

    private function denied(MarginRefused $refused): \Throwable
    {
        return RefusalKind::Forbidden === $refused->kind()
            ? new AccessDeniedHttpException($refused->getMessage(), $refused)
            : new NotFoundHttpException($refused->getMessage(), $refused);
    }
}
