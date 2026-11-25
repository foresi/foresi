<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Infrastructure\Controller;

use Foresi\Catalog\Contract\BillingKind;
use Foresi\Catalog\Contract\Products;
use Foresi\Catalog\Contract\ProductSnapshot;
use Foresi\Sales\Application\Api\AddDealLineInput;
use Foresi\Sales\Application\Api\RemoveDealLineInput;
use Foresi\Sales\Application\Api\ReorderDealLinesInput;
use Foresi\Sales\Application\Api\UpdateDealLineInput;
use Foresi\Sales\Application\DealDetail;
use Foresi\Sales\Application\DealLineWrites;
use Foresi\Sales\Application\DealReads;
use Foresi\Sales\Application\LineProject;
use Foresi\Sales\Application\LineProjects;
use Foresi\Sales\Application\SalesRefused;
use Foresi\Sales\Domain\DealLine;
use Foresi\Sales\Infrastructure\Screen\DealScreen;
use Foresi\Shared\Application\Api\Omitted;
use Foresi\Shared\Application\RefusalKind;
use Foresi\Shared\Domain\Money;
use Foresi\Shared\Infrastructure\Http\TypedNumber;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * The lines of a deal. A new line is picked from the catalog, which copies the
 * product with the quantity given, or typed in with its own name, unit, price
 * and billing, as Pipedrive and HubSpot add products to a deal; Edit opens the
 * line in a dialog, Up and Down move it and Remove asks first. Without
 * JavaScript the dialog is a page of its own.
 */
final class DealLineController extends AbstractController
{
    private const array TYPED = ['name', 'unit', 'unitPrice', 'currency', 'billingKind', 'recurrenceUnit', 'interval', 'cycles', 'projectId'];

    public function __construct(
        private readonly DealReads $reads,
        private readonly DealLineWrites $writes,
        private readonly Products $products,
        private readonly DealScreen $screen,
        private readonly LineProjects $projects,
    ) {
    }

    #[Route('/sales/deals/{id}/lines/new', name: 'app_sales_deal_line_new', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function new(string $id, Request $request): Response
    {
        $detail = $this->detail($id);
        $values = $this->screen->lineValues(null);
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            $values = $this->posted($request) + $values;
            try {
                if ('' !== $values['productId']) {
                    // A line from the catalog copies the product; only its quantity is given.
                    $this->writes->add(new AddDealLineInput($id, $values['productId'], quantity: $this->quantity($values['quantity'], $request->getLocale())));
                } else {
                    $d = $this->draft($values, $request->getLocale());
                    $this->writes->add(new AddDealLineInput($id, null, $d['name'], $d['unit'], $d['quantity'], $d['unitPrice'], $d['currency'], $d['billingKind'], $d['recurrenceUnit'], $d['interval'], $d['cycles'], $d['projectId']));
                }
                $this->addFlash('success', $this->screen->trans('sales.line.flash.added'));

                return $this->back($id);
            } catch (SalesRefused $refused) {
                $this->denyIfForbidden($refused);
                $errors = $this->errors($refused, '' !== $values['productId']);
            }
        }

        return $this->form($detail, null, $values, $errors);
    }

    #[Route('/sales/deals/{id}/lines/{lineId}/edit', name: 'app_sales_deal_line_edit', requirements: ['id' => Requirement::UUID, 'lineId' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function edit(string $id, string $lineId, Request $request): Response
    {
        $detail = $this->detail($id);
        $line = $this->line($detail, $lineId);
        $values = $this->screen->lineValues($line);
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            $values = $this->posted($request) + $values;
            try {
                $d = $this->draft($values, $request->getLocale());
                // Without Work installed the form has no project field, and the line keeps what it has.
                $this->writes->update(new UpdateDealLineInput($id, $lineId, $d['name'], $d['unit'], $d['quantity'], $d['unitPrice'], $d['currency'], $d['billingKind'], $d['recurrenceUnit'], $d['interval'], $d['cycles'], $this->projects->installed() ? $d['projectId'] : new Omitted()));
                $this->addFlash('success', $this->screen->trans('sales.line.flash.saved'));

                return $this->back($id);
            } catch (SalesRefused $refused) {
                $this->denyIfForbidden($refused);
                $errors = $this->errors($refused, false);
            }
        }

        return $this->form($detail, $line, $values, $errors);
    }

    #[Route('/sales/deals/{id}/lines/{lineId}/remove', name: 'app_sales_deal_line_remove', requirements: ['id' => Requirement::UUID, 'lineId' => Requirement::UUID], methods: ['POST'])]
    public function remove(string $id, string $lineId, Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $this->act(fn (): mixed => $this->writes->remove(new RemoveDealLineInput($id, $lineId)), 'sales.line.flash.removed');

        return $this->back($id);
    }

    /**
     * Moves a line one place up or down: the up and down buttons a list without dragging keeps.
     */
    #[Route('/sales/deals/{id}/lines/{lineId}/move', name: 'app_sales_deal_line_move', requirements: ['id' => Requirement::UUID, 'lineId' => Requirement::UUID], methods: ['POST'])]
    public function move(string $id, string $lineId, Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $ids = array_map(static fn (DealLine $line): string => $line->id(), $this->detail($id)->lines);
        $at = array_search($lineId, $ids, true);
        if (false === $at) {
            throw new NotFoundHttpException('The deal has no such line.');
        }
        $to = 'up' === $request->request->getString('direction') ? $at - 1 : $at + 1;
        if ($to >= 0 && $to < \count($ids)) {
            [$ids[$at], $ids[$to]] = [$ids[$to], $ids[$at]];
            $this->act(fn (): mixed => $this->writes->reorder(new ReorderDealLinesInput($id, array_values($ids))), null);
        }

        return $this->back($id);
    }

    /**
     * @param array<string, string> $values
     * @param array<string, string> $errors
     */
    private function form(DealDetail $detail, ?DealLine $line, array $values, array $errors): Response
    {
        return $this->render('@Sales/lines/form.html.twig', [
            'deal' => $detail->deal,
            'line' => $line,
            'action' => $line instanceof DealLine
                ? $this->generateUrl('app_sales_deal_line_edit', ['id' => $detail->deal->id(), 'lineId' => $line->id()])
                : $this->generateUrl('app_sales_deal_line_new', ['id' => $detail->deal->id()]),
            'values' => $values,
            'errors' => $errors,
            'products' => $line instanceof DealLine ? [] : $this->productOptions(),
            'projects' => $this->projectOptions($detail, $line),
        ] + $this->screen->lineOptions(), new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /**
     * The products of the catalog a line can be picked from, each with its price and billing.
     *
     * @return array<string, string>
     */
    private function productOptions(): array
    {
        $options = [];
        foreach ($this->products->search('') as $product) {
            $options[$product->id] = $this->productLabel($product);
        }

        return $options;
    }

    /**
     * The projects an hourly line may link to: the active ones of the client and
     * of no client, and the
     * one the line has, archived or not; null without Work, so the form has no field.
     *
     * @return array<string, string>|null
     */
    private function projectOptions(DealDetail $detail, ?DealLine $line): ?array
    {
        if (!$this->projects->installed()) {
            return null;
        }
        $options = ['' => $this->screen->trans('sales.line.no_project')];
        foreach ($this->projects->ofClient($detail->deal->clientPartyId()) as $project) {
            $options[$project->id] = $project->name;
        }
        $linked = $line?->projectId();
        if (null !== $linked && !isset($options[$linked])) {
            $project = $this->projects->find($linked);
            $options[$linked] = $project instanceof LineProject
                ? $project->name.($project->archived ? ' · '.$this->screen->trans('sales.line.project_archived') : '')
                : $this->screen->trans('sales.line.project_hidden');
        }

        return $options;
    }

    private function productLabel(ProductSnapshot $product): string
    {
        $price = $product->unitPrice;
        $parts = [$product->name];
        if ($price instanceof Money) {
            $parts[] = $this->screen->money($price).('' === $product->unit ? '' : ' / '.$product->unit);
        }
        $parts[] = $this->screen->trans('sales.billing_kind.'.$product->billingKind->value);

        return implode(' · ', $parts);
    }

    /**
     * @return array<string, string>
     */
    private function posted(Request $request): array
    {
        $values = ['productId' => trim($request->request->getString('productId')), 'quantity' => trim($request->request->getString('quantity'))];
        foreach (self::TYPED as $field) {
            $values[$field] = trim($request->request->getString($field));
        }

        return $values;
    }

    /**
     * The form's values as the use cases take them: a one-off line sends no period,
     * and a line other than an hourly one no project, which the form hides but
     * still sends without JavaScript.
     *
     * @param array<string, string> $values
     *
     * @return array{name: string, unit: string, quantity: ?string, unitPrice: ?string, currency: string, billingKind: string, recurrenceUnit: ?string, interval: ?int, cycles: ?int, projectId: ?string}
     *
     * @throws SalesRefused
     */
    private function draft(array $values, string $locale): array
    {
        $recurs = BillingKind::Recurring->value === $values['billingKind'];
        $interval = null;
        $cycles = null;
        if ($recurs) {
            if (1 !== preg_match('/^\d{1,2}$/', $values['interval'])) {
                throw SalesRefused::invalid('interval_invalid', 'interval', 'The interval is a whole number from 1 to 99.');
            }
            $interval = (int) $values['interval'];
            if ('' !== $values['cycles']) {
                if (1 !== preg_match('/^\d{1,3}$/', $values['cycles'])) {
                    throw SalesRefused::invalid('cycles_invalid', 'cycles', 'The cycles are a whole number from 1 to 999.');
                }
                $cycles = (int) $values['cycles'];
            }
        }
        $price = TypedNumber::decimal($values['unitPrice'], $locale);

        return [
            'name' => $values['name'],
            'unit' => $values['unit'],
            'quantity' => $this->quantity($values['quantity'], $locale),
            'unitPrice' => '' === $price ? null : $price,
            'currency' => $values['currency'],
            'billingKind' => $values['billingKind'],
            'recurrenceUnit' => $recurs && '' !== $values['recurrenceUnit'] ? $values['recurrenceUnit'] : null,
            'interval' => $interval,
            'cycles' => $cycles,
            // Only an hourly line links to a project; the form hides the field for the other kinds.
            'projectId' => BillingKind::TimeBased->value === $values['billingKind'] && '' !== $values['projectId'] ? $values['projectId'] : null,
        ];
    }

    /**
     * Empty is no quantity: an hourly line has none, any other line then counts as one unit.
     */
    private function quantity(string $typed, string $locale): ?string
    {
        $quantity = TypedNumber::decimal($typed, $locale);

        return '' === $quantity ? null : $quantity;
    }

    /**
     * @return array<string, string> the message of the refused field, by form field
     */
    private function errors(SalesRefused $refused, bool $fromProduct): array
    {
        $field = $refused->field();
        $field = match (true) {
            'productId' === $field, $fromProduct && 'quantity' !== $field => 'productId',
            null !== $field && \in_array($field, ['quantity', ...self::TYPED], true) => $field,
            default => 'name',
        };

        return [$field => $this->screen->refusal($refused)];
    }

    /**
     * @param \Closure(): mixed $write
     */
    private function act(\Closure $write, ?string $flash): void
    {
        try {
            $write();
            if (null !== $flash) {
                $this->addFlash('success', $this->screen->trans($flash));
            }
        } catch (SalesRefused $refused) {
            $this->denyIfForbidden($refused);
            if (RefusalKind::NotFound === $refused->kind()) {
                throw new NotFoundHttpException($refused->getMessage(), $refused);
            }
            $this->addFlash('error', $this->screen->refusal($refused));
        }
    }

    /**
     * A deal not in the trash, whose lines may change.
     */
    private function detail(string $id): DealDetail
    {
        try {
            $detail = $this->reads->deal($id);
        } catch (SalesRefused $refused) {
            $this->denyIfForbidden($refused);
            throw new NotFoundHttpException($refused->getMessage(), $refused);
        }
        if ($detail->deal->isTrashed()) {
            throw new NotFoundHttpException('The deal is in the trash.');
        }

        return $detail;
    }

    private function line(DealDetail $detail, string $lineId): DealLine
    {
        foreach ($detail->lines as $line) {
            if ($line->id() === $lineId) {
                return $line;
            }
        }
        throw new NotFoundHttpException('The deal has no such line.');
    }

    private function back(string $id): RedirectResponse
    {
        return $this->redirectToRoute(DealController::DEAL_ROUTE, ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    private function denyIfForbidden(SalesRefused $refused): void
    {
        if (RefusalKind::Forbidden === $refused->kind()) {
            throw new AccessDeniedHttpException($refused->getMessage(), $refused);
        }
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(DealController::TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
