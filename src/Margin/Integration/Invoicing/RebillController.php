<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Integration\Invoicing;

use Foresi\Margin\Application\CostPairings;
use Foresi\Margin\Application\RebillLinks;
use Foresi\Margin\Application\RebillLinkView;
use Foresi\Margin\Application\RebillPairings;
use Foresi\Margin\Application\RevenueSources;
use Foresi\Margin\Contract\RevenueDocument;
use Foresi\Margin\Domain\MarginRefused;
use Foresi\Shared\Contract\RefusalKind;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What a person says about rebilling, from the row menu of a line: which planned cost a line of a recurring invoice
 * rebills, which occurrence of a cost a line of an issued invoice is paired with, and taking that pairing away. Each is
 * a dialog over the invoice, and a page of its own at the same URL; a refusal is told in the form, and a saved decision
 * returns to the invoice or the template, where the badge of the line shows it.
 */
final class RebillController extends AbstractController
{
    public const string TOKEN = 'margin_rebill';

    public function __construct(
        private readonly RebillLinks $links,
        private readonly RebillPairings $pairings,
        private readonly RevenueSources $revenue,
        private readonly CostPairings $costs,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/margin/rebills/recurring-lines/{id}', name: 'app_margin_rebill_link', requirements: ['id' => Requirement::UUID_V7], methods: ['GET', 'POST'])]
    public function link(string $id, Request $request): Response
    {
        $current = $this->guard(fn (): ?RebillLinkView => $this->links->ofLine($id));
        $line = $this->revenue->templateLine($id) ?? throw new NotFoundHttpException();
        $values = ['plannedItemId' => $current->plannedItemId ?? ''];
        $error = null;
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            $values = ['plannedItemId' => $request->request->getString('plannedItemId')];
            try {
                $link = $this->links->set($id, '' === $values['plannedItemId'] ? null : $values['plannedItemId']);
                $this->addFlash('success', $this->translator->trans($link instanceof RebillLinkView ? 'margin.rebill.flash.link_set' : 'margin.rebill.flash.link_removed', ['name' => $link->plannedItemName ?? '']));
                foreach ($link->warnings ?? [] as $warning) {
                    $this->addFlash('warning', $this->translator->trans('margin.rebill.warning.'.$warning));
                }

                return $this->redirect($line->url, Response::HTTP_SEE_OTHER);
            } catch (MarginRefused $refused) {
                $error = $this->told($refused);
            }
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        }

        return $this->render('@Margin/invoicing/link.html.twig', [
            'line' => $line,
            'back' => $line->url,
            'linked' => null !== $current,
            'options' => $this->options(),
            'values' => $values,
            'error' => $error,
        ], new Response(status: $status));
    }

    #[Route('/margin/rebills/invoice-lines/{id}', name: 'app_margin_rebill_pair', requirements: ['id' => Requirement::UUID_V7], methods: ['GET', 'POST'])]
    public function pair(string $id, Request $request): Response
    {
        $document = $this->document($id);
        $values = ['plannedItemId' => '', 'occurrenceOn' => $document->taxPointOn->format('Y-m-d')];
        $error = null;
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            $values = ['plannedItemId' => $request->request->getString('plannedItemId'), 'occurrenceOn' => $request->request->getString('occurrenceOn')];
            if ('' === $values['plannedItemId']) {
                $error = $this->translator->trans('margin.rebill.error.item');
            } else {
                try {
                    $pairing = $this->pairings->pair($id, $values['plannedItemId'], $values['occurrenceOn']);
                    $this->addFlash('success', $this->translator->trans('margin.rebill.flash.paired', ['name' => $pairing->plannedItemName]));

                    return $this->redirect($document->url, Response::HTTP_SEE_OTHER);
                } catch (MarginRefused $refused) {
                    $error = $this->told($refused);
                }
            }
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        }

        return $this->render('@Margin/invoicing/pair.html.twig', [
            'lineId' => $id,
            'number' => $document->number,
            'back' => $document->url,
            'options' => $this->options(),
            'values' => $values,
            'error' => $error,
        ], new Response(status: $status));
    }

    #[Route('/margin/rebills/invoice-lines/{id}/unpair', name: 'app_margin_rebill_unpair', requirements: ['id' => Requirement::UUID_V7], methods: ['GET', 'POST'])]
    public function unpair(string $id, Request $request): Response
    {
        $document = $this->document($id);
        $pairing = $this->guard(fn () => $this->pairings->ofLines([$id])[$id] ?? null) ?? throw new NotFoundHttpException();
        $error = null;
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            try {
                $this->pairings->unpair($id);
                $this->addFlash('success', $this->translator->trans('margin.rebill.flash.unpaired'));

                return $this->redirect($document->url, Response::HTTP_SEE_OTHER);
            } catch (MarginRefused $refused) {
                $error = $this->told($refused);
            }
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        }

        return $this->render('@Margin/invoicing/unpair.html.twig', [
            'lineId' => $id,
            'number' => $document->number,
            'back' => $document->url,
            'pairing' => $pairing,
            'error' => $error,
        ], new Response(status: $status));
    }

    private function document(string $lineId): RevenueDocument
    {
        // Who may not work with the margin is turned away before anything of the invoice is told.
        $this->guard(fn (): array => $this->pairings->ofLines([]));

        return $this->revenue->documentOfLine($lineId) ?? throw new NotFoundHttpException();
    }

    /**
     * The planned costs to pick from, by name, with the empty choice first.
     *
     * @return array<string, string>
     */
    private function options(): array
    {
        $options = ['' => $this->translator->trans('margin.rebill.none')];
        foreach ($this->costs->costItems() as $item) {
            $options[$item->id] = $item->name;
        }

        return $options;
    }

    /**
     * @template T
     *
     * @param \Closure(): T $read
     *
     * @return T
     */
    private function guard(\Closure $read): mixed
    {
        try {
            return $read();
        } catch (MarginRefused $refused) {
            throw $this->denied($refused);
        }
    }

    private function told(MarginRefused $refused): string
    {
        if (RefusalKind::Forbidden === $refused->kind()) {
            throw $this->denied($refused);
        }

        return $this->translator->trans('margin.refused.'.$refused->reason());
    }

    private function denied(MarginRefused $refused): \Throwable
    {
        return RefusalKind::NotFound === $refused->kind() ? new NotFoundHttpException($refused->getMessage(), $refused) : new AccessDeniedHttpException($refused->getMessage(), $refused);
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
