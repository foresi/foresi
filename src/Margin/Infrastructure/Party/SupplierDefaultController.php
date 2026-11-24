<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Infrastructure\Party;

use Foresi\Instance\Application\InstanceClock;
use Foresi\Margin\Application\AllocationReads;
use Foresi\Margin\Application\AllocationWrites;
use Foresi\Margin\Application\SetSupplierDefaultInput;
use Foresi\Margin\Application\SupplierDefaultsView;
use Foresi\Margin\Domain\MarginRefused;
use Foresi\Party\Contract\ContactTabs;
use Foresi\Shared\Application\RefusalKind;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The Margin tab of a contact (ContactTabs) and its dialog: who bears the costs of this supplier by default, the
 * default in force with the month it applies from, earlier and later versions folded below, and "Edit" in the
 * header to set the recipient from a month on (the firm itself, a client, or nobody, which ends the default). The
 * tab is the contact tab's Turbo frame; the dialog is also a page of its own at the same URL.
 */
final class SupplierDefaultController extends AbstractController
{
    public const string ROUTE = 'app_margin_supplier_default';
    public const string EDIT_ROUTE = 'app_margin_supplier_default_edit';
    public const string TOKEN = 'margin_supplier_default';

    private const string MONTH = '/^[0-9]{4}-(0[1-9]|1[0-2])\z/';

    public function __construct(
        private readonly AllocationReads $reads,
        private readonly AllocationWrites $writes,
        private readonly InstanceClock $clock,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/margin/suppliers/{id}/default', name: self::ROUTE, requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function tab(string $id): Response
    {
        $view = $this->view($id);
        $versions = [];
        $placed = 'later';
        foreach ($view->versions as $version) {
            // Latest first: what comes before the version in force starts later, what comes after it is earlier.
            $placed = $version->current ? 'current' : ('current' === $placed ? 'earlier' : $placed);
            $versions[] = ['month' => $version->fromMonth, 'placed' => $placed, 'recipient' => $version->recipient, 'name' => $version->recipientName];
        }

        return $this->render('@Margin/supplier/default.html.twig', [
            'frame' => ContactTabs::FRAME,
            'contactId' => $id,
            'current' => array_find($versions, static fn (array $version): bool => 'current' === $version['placed']),
            'others' => array_values(array_filter($versions, static fn (array $version): bool => 'current' !== $version['placed'])),
        ]);
    }

    #[Route('/margin/suppliers/{id}/default/edit', name: self::EDIT_ROUTE, requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request): Response
    {
        $view = $this->view($id);
        $values = $this->starting($view);
        $error = null;
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            $values = [
                'type' => $request->request->getString('type'),
                'contactId' => $request->request->getString('contactId'),
                'query' => $request->request->getString('query'),
                'fromMonth' => trim($request->request->getString('fromMonth')),
            ];
            $recipient = match ($values['type']) {
                'own' => 'own',
                'client' => $values['contactId'],
                default => null,
            };
            if (1 !== preg_match(self::MONTH, $values['fromMonth'])) {
                $error = $this->translator->trans('margin.refused.invalid_month');
            } elseif ('client' === $values['type'] && '' === $values['contactId']) {
                $error = $this->translator->trans('margin.screen.error.client');
            } else {
                try {
                    $this->writes->setSupplierDefaultRecipient(new SetSupplierDefaultInput($id, $recipient, $values['fromMonth']));
                    $this->addFlash('success', $this->translator->trans(null === $recipient ? 'margin.supplier.flash.ended' : 'margin.supplier.flash.set'));

                    return $this->redirectToRoute('app_contact_show', ['id' => $id, 'tab' => MarginContactTab::KEY], Response::HTTP_SEE_OTHER);
                } catch (MarginRefused $refused) {
                    $error = $this->told($refused);
                }
            }
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        }

        return $this->render('@Margin/supplier/edit.html.twig', [
            'contactId' => $id,
            'name' => $view->supplierName,
            'values' => $values,
            'error' => $error,
        ], new Response(status: $status));
    }

    /**
     * What the dialog starts with: the default in force, or nobody, and this month.
     *
     * @return array{type: string, contactId: string, query: string, fromMonth: string}
     */
    private function starting(SupplierDefaultsView $view): array
    {
        $values = ['type' => '', 'contactId' => '', 'query' => '', 'fromMonth' => $this->clock->today()->format('Y-m')];
        foreach ($view->versions as $version) {
            if ($version->current && null !== $version->recipient) {
                $own = 'own' === $version->recipient;
                $values = [...$values, 'type' => $own ? 'own' : 'client', 'contactId' => $own ? '' : $version->recipient, 'query' => $version->recipientName ?? ''];
            }
        }

        return $values;
    }

    private function view(string $id): SupplierDefaultsView
    {
        try {
            return $this->reads->supplierDefault($id);
        } catch (MarginRefused $refused) {
            throw $this->denied($refused);
        }
    }

    private function told(MarginRefused $refused): string
    {
        if (RefusalKind::Forbidden === $refused->kind() || RefusalKind::NotFound === $refused->kind()) {
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
