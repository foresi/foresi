<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Integration\ReceivedDocuments;

use Foresi\Margin\Application\AllocationReads;
use Foresi\Margin\Application\AllocationWrites;
use Foresi\Margin\Application\AssignDocumentInput;
use Foresi\Margin\Application\DocumentAllocationView;
use Foresi\Margin\Application\LineAllocationView;
use Foresi\Margin\Application\SetLineAllocationInput;
use Foresi\Margin\Domain\MarginRefused;
use Foresi\Margin\Infrastructure\Screen\MappingForm;
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
 * What a person decides about who bears the cost of a received document, from its Margin panel: assign the whole
 * document to the firm or a client, or set the split of one line. Each is a dialog over the document, and a page
 * of its own at the same URL; a refusal is told in the form, and a saved decision returns to the document.
 */
final class MarginController extends AbstractController
{
    public const string TOKEN = 'margin_screens';

    private const string LINE_KEY = '[A-Za-z0-9:._-]{1,64}';

    public function __construct(
        private readonly AllocationReads $reads,
        private readonly AllocationWrites $writes,
        private readonly MappingForm $form,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/margin/documents/{id}/assign', name: 'app_margin_assign', requirements: ['id' => Requirement::UUID_V7], methods: ['GET', 'POST'])]
    public function assign(string $id, Request $request): Response
    {
        $view = $this->view($id);
        $values = ['type' => $this->type($view->documentRecipient), 'contactId' => $this->contactId($view->documentRecipient), 'query' => $view->documentRecipientName ?? ''];
        $error = null;
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            $clear = 'clear' === $request->request->getString('_action');
            $values = ['type' => $request->request->getString('type'), 'contactId' => $request->request->getString('contactId'), 'query' => $request->request->getString('query')];
            $recipient = match ($values['type']) {
                'own' => 'own',
                'client' => $values['contactId'],
                default => '',
            };
            if (!$clear && '' === $recipient) {
                $error = $this->translator->trans('margin.screen.error.recipient');
            } else {
                try {
                    $this->writes->assign(new AssignDocumentInput($id, $clear ? null : $recipient));
                    $this->addFlash('success', $this->translator->trans($clear ? 'margin.screen.flash.cleared' : 'margin.screen.flash.assigned'));

                    return $this->redirectToRoute('app_received_document', ['id' => $id], Response::HTTP_SEE_OTHER);
                } catch (MarginRefused $refused) {
                    $error = $this->told($refused);
                }
            }
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        }

        return $this->render('@Margin/purchasing/assign.html.twig', [
            'documentId' => $id,
            'title' => $view->label,
            'view' => $view,
            'values' => $values,
            'error' => $error,
        ], new Response(status: $status));
    }

    #[Route('/margin/documents/{id}/lines/{key}', name: 'app_margin_line', requirements: ['id' => Requirement::UUID_V7, 'key' => self::LINE_KEY], methods: ['GET', 'POST'])]
    public function line(string $id, string $key, Request $request): Response
    {
        $view = $this->view($id);
        $line = array_find($view->lines, static fn (LineAllocationView $candidate): bool => $candidate->key === $key) ?? throw new NotFoundHttpException();
        $values = $this->form->fromMapping($line->mapping);
        $error = null;
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            $values = $this->form->fromRequest($request->request);
            $clear = 'clear' === $request->request->getString('_action');
            $input = $clear ? new SetLineAllocationInput($id, $key, null) : $this->form->input($id, $key, $values);
            if (\is_string($input)) {
                $error = $this->translator->trans($input);
            } else {
                try {
                    $this->writes->setLine($input);
                    $this->addFlash('success', $this->translator->trans($clear ? 'margin.screen.flash.line_cleared' : 'margin.screen.flash.line_set'));

                    return $this->redirectToRoute('app_received_document', ['id' => $id], Response::HTTP_SEE_OTHER);
                } catch (MarginRefused $refused) {
                    $error = $this->told($refused);
                }
            }
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        }

        return $this->render('@Margin/purchasing/line.html.twig', [
            'documentId' => $id,
            'title' => $view->label,
            'view' => $view,
            'line' => $line,
            'values' => $values,
            'error' => $error,
        ], new Response(status: $status));
    }

    private function view(string $id): DocumentAllocationView
    {
        try {
            return $this->reads->document($id);
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

    private function type(?string $recipient): string
    {
        return null === $recipient ? '' : ('own' === $recipient ? 'own' : 'client');
    }

    private function contactId(?string $recipient): string
    {
        return null === $recipient || 'own' === $recipient ? '' : $recipient;
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
