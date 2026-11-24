<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Integration\CashFlow;

use Foresi\CashFlow\Contract\FlowDirection;
use Foresi\CashFlow\Contract\PlannedItemCounterparties;
use Foresi\CashFlow\Contract\PlannedItemDirectory;
use Foresi\CashFlow\Contract\PlannedItemSummary;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Margin\Application\AllocationReads;
use Foresi\Margin\Application\AllocationWrites;
use Foresi\Margin\Application\PlannedAllocationsView;
use Foresi\Margin\Application\SetPlannedAllocationInput;
use Foresi\Margin\Domain\MarginRefused;
use Foresi\Margin\Infrastructure\Screen\MappingForm;
use Foresi\Shared\Application\RefusalKind;
use Foresi\Shared\Application\Refused;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What a person decides about how a recurring planned cost is split among clients, from its panel: the kind and the
 * shares, the month they apply from, or no mapping from that month. A dialog over the planned item, and a page of its
 * own at the same URL. A planned item without a counterparty is given the supplier in the same request, before the
 * mapping, because no document is paired with an item that has none. If the mapping is then refused, the supplier
 * stays: it is a valid change of its own, and the form, which no longer asks for it, tells the refusal.
 *
 * @phpstan-import-type Row from MappingForm
 *
 * @phpstan-type Form array{kind: string, rows: list<Row>, fromMonth: string}
 */
final class PlannedMappingController extends AbstractController
{
    public const string TOKEN = 'margin_planned_mapping';
    public const string ROUTE = 'app_margin_planned';

    private const string NONE = 'none';
    private const string MONTH = '/^[0-9]{4}-(0[1-9]|1[0-2])\z/';

    public function __construct(
        private readonly PlannedItemDirectory $items,
        private readonly PlannedItemCounterparties $counterparties,
        private readonly AllocationReads $reads,
        private readonly AllocationWrites $writes,
        private readonly MappingForm $form,
        private readonly InstanceClock $clock,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/margin/planned-items/{id}/allocation', name: self::ROUTE, requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request): Response
    {
        $view = $this->view($id);
        $item = $this->item($id);
        $values = $this->fromView($view);
        $supplier = ['id' => '', 'text' => ''];
        $error = null;
        $supplierSaved = false;
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            $values = [...$this->form->fromRequest($request->request), 'fromMonth' => trim($request->request->getString('fromMonth'))];
            $supplier = ['id' => $request->request->getString('supplierId'), 'text' => $request->request->getString('supplierQuery')];
            $error = $this->refusal($values);
            if (null === $error) {
                [$error, $supplierSaved] = $this->save($item, $values, $supplier['id']);
                if (null === $error) {
                    $this->addFlash('success', $this->translator->trans(self::NONE === $values['kind'] ? 'margin.planned.flash.ended' : 'margin.planned.flash.set'));

                    return $this->redirect($item->url, Response::HTTP_SEE_OTHER);
                }
                $item = $this->item($id);
            }
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        }

        return $this->render('@Margin/cash_flow/planned.html.twig', [
            'itemId' => $id,
            'item' => $item,
            'name' => $view->name,
            'back' => $item->url,
            'hasMapping' => array_any($view->versions, static fn (\Foresi\Margin\Application\PlannedAllocationVersionView $version): bool => $version->mapping instanceof \Foresi\Margin\Application\MappingView),
            'asksSupplier' => null === $item->counterpartyId,
            'supplier' => $supplier,
            'values' => $values,
            'error' => $error,
            'supplierSaved' => $supplierSaved,
        ], new Response(status: $status));
    }

    /**
     * What is wrong with the form itself, told before anything is saved; null when it can be tried.
     *
     * @param Form $values
     */
    private function refusal(array $values): ?string
    {
        if (1 !== preg_match(self::MONTH, $values['fromMonth'])) {
            return $this->translator->trans('margin.refused.invalid_month');
        }
        if (self::NONE !== $values['kind'] && !\in_array($values['kind'], ['whole', 'percent', 'fixed'], true)) {
            return $this->translator->trans('margin.refused.kind_invalid');
        }

        return null;
    }

    /**
     * @param Form $values
     *
     * @return array{0: ?string, 1: bool} what to tell, and whether the supplier was saved on the way
     */
    private function save(PlannedItemSummary $item, array $values, string $supplierId): array
    {
        $shares = null;
        if (self::NONE !== $values['kind']) {
            $shares = $this->form->shares($values);
            if (\is_string($shares)) {
                return [$this->translator->trans($shares), false];
            }
        }
        $saved = false;
        try {
            if (null !== $shares && null === $item->counterpartyId) {
                if ('' === $supplierId) {
                    return [$this->translator->trans('margin.planned.error.supplier'), false];
                }
                $this->counterparties->set($item->id, $supplierId);
                $saved = true;
            }
            $this->writes->setPlannedAllocation(new SetPlannedAllocationInput($item->id, null === $shares ? null : ['kind' => $values['kind'], 'shares' => $shares], $values['fromMonth']));
        } catch (Refused $refused) {
            return [$this->told($refused), $saved];
        }

        return [null, false];
    }

    private function told(Refused $refused): string
    {
        if (RefusalKind::Forbidden === $refused->kind() || RefusalKind::NotFound === $refused->kind()) {
            throw $this->denied($refused);
        }

        return $this->translator->trans($refused instanceof MarginRefused ? 'margin.refused.'.$refused->reason() : 'margin.planned.error.supplier_refused');
    }

    private function denied(Refused $refused): \Throwable
    {
        return RefusalKind::NotFound === $refused->kind() ? new NotFoundHttpException($refused->getMessage(), $refused) : new AccessDeniedHttpException($refused->getMessage(), $refused);
    }

    private function view(string $id): PlannedAllocationsView
    {
        try {
            return $this->reads->planned($id);
        } catch (MarginRefused $refused) {
            throw $this->denied($refused);
        }
    }

    /**
     * The planned item, when it is a recurring cost the margin maps.
     */
    private function item(string $id): PlannedItemSummary
    {
        $item = $this->items->one($id);
        if (!$item instanceof PlannedItemSummary || FlowDirection::Out !== $item->direction || !$item->recurring) {
            throw new NotFoundHttpException('Only a recurring planned cost is mapped to clients.');
        }

        return $item;
    }

    /**
     * What the dialog starts with: the mapping in force, or its end, and this month.
     *
     * @return Form
     */
    private function fromView(PlannedAllocationsView $view): array
    {
        $current = array_find($view->versions, static fn ($version): bool => $version->current);
        $values = $this->form->fromMapping($current?->mapping);
        if (null !== $current && null === $current->mapping) {
            $values['kind'] = self::NONE;
        }

        return [...$values, 'fromMonth' => $this->clock->today()->format('Y-m')];
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
