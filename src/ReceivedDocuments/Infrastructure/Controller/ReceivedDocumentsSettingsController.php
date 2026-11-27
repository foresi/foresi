<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\ReceivedDocuments\Infrastructure\Controller;

use Foresi\Identity\Contract\StaffCheck;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Mail\Contract\AddressIssuer;
use Foresi\Mail\Contract\AddressNotIssued;
use Foresi\Mail\Contract\AddressUnavailable;
use Foresi\Mail\Contract\OwnAddress;
use Foresi\Mail\Contract\OwnAddresses;
use Foresi\ReceivedDocuments\Application\Mail\ReceivedDocumentsPurpose;
use Foresi\ReceivedDocuments\Application\Mail\RecentMail;
use Foresi\ReceivedDocuments\Application\Mail\RecentMails;
use Foresi\ReceivedDocuments\Application\View\CaptureMailLists;
use Foresi\ReceivedDocuments\Infrastructure\Column\CaptureMailColumns;
use Foresi\Shared\Application\View\ListSlot;
use Foresi\Shared\Contract\Refused;
use Foresi\Shared\Infrastructure\View\FilterSheet;
use Foresi\Ui\Contract\WorkingState;
use Foresi\Ui\View\ListTable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Settings → Received documents: the signed-in person's capture address, the
 * steps that forward supplier mail to it, and the recent e-mails it took in
 * with what came of each. Mail keeps the address (OwnAddresses); a new one and
 * a rename open in a dialog from the page header, and Replace and Revoke ask
 * first, as the bank address of an account does. The address is shown in full
 * to its owner only, with a copy button, and the page is never cached. Below
 * them come the sections other modules add (PurchasingSlots::SETTINGS_SECTIONS),
 * such as the state of a reader.
 */
final class ReceivedDocumentsSettingsController extends AbstractController
{
    public const string ROUTE = 'app_received_documents_settings';
    public const string TOKEN = 'purchasing_capture_address';
    public const string MAIL_GUIDE = 'https://github.com/foresi/foresi/blob/main/docs/mail.md';

    public function __construct(
        private readonly StaffCheck $staff,
        private readonly OwnAddresses $own,
        private readonly AddressIssuer $issuer,
        private readonly RecentMails $recent,
        private readonly TranslatorInterface $translator,
        private readonly InstanceClock $clock,
        private readonly CaptureMailLists $lists,
        private readonly ListTable $listTable,
        private readonly FilterSheet $filterSheet,
    ) {
    }

    #[Route('/settings/received-documents', name: self::ROUTE, methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyUnlessStaff();
        $slot = ListSlot::first($request->query->all());
        $list = $this->lists->list();
        // The builder's rows (spec D11) become the list's working filter, in its address.
        $built = $this->listTable->built($slot, $list);
        if (null !== $built) {
            return $this->redirectToRoute(self::ROUTE, $built, Response::HTTP_SEE_OTHER);
        }
        $address = $this->own->mine(ReceivedDocumentsPurpose::KEY);
        $revealed = null;
        if ($address instanceof OwnAddress) {
            try {
                $revealed = $this->own->reveal($address->id);
            } catch (AddressNotIssued) {
                // The server lost its template or key; the page says the address cannot be shown.
            }
        }
        $mails = $this->recent->mine();
        $page = $this->listTable->of($slot, $list, CaptureMailColumns::LIST, self::ROUTE, 'capture-mails', $mails, $this->recentRow(...), $this->recentValue(...), static fn (RecentMail $mail): string => $mail->from.' '.$mail->subject, $this->translator->trans('received_documents.capture.recent.search'));
        $response = new Response();
        $response->headers->set('Cache-Control', 'private, no-store');

        return $this->render('@ReceivedDocuments/settings.html.twig', [
            'address' => $address,
            'revealed' => $revealed,
            'lastReceived' => $address?->lastReceivedAt?->setTimezone($this->clock->timezone())->format($this->translator->trans('format.datetime')),
            'unavailable' => $this->unavailable(),
            'mailGuide' => self::MAIL_GUIDE,
            'hasRecent' => [] !== $mails,
            'recent' => $page->with($this->filterSheet->of($list, $page->shown, $page->href, pinned: $page->pinned, slot: $slot)),
        ], $response);
    }

    #[Route('/settings/received-documents/capture-address/new', name: 'app_capture_address_new', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        $this->denyUnlessStaff();

        return $this->labelForm($request, null, '');
    }

    #[Route('/settings/received-documents/capture-address/{id}/rename', name: 'app_capture_address_rename', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function rename(string $id, Request $request): Response
    {
        $this->denyUnlessStaff();
        $address = $this->captureAddress($id);

        return $this->labelForm($request, $address, $address->label);
    }

    #[Route('/settings/received-documents/capture-address/{id}/replace', name: 'app_capture_address_replace', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function replace(string $id, Request $request): RedirectResponse
    {
        $this->denyUnlessStaff();
        $this->captureAddress($id);
        $this->checkToken($request);
        $this->act(fn (): mixed => $this->own->replace($id), 'received_documents.capture.flash.replaced');

        return $this->redirectToRoute(self::ROUTE, status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/settings/received-documents/capture-address/{id}/revoke', name: 'app_capture_address_revoke', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function revoke(string $id, Request $request): RedirectResponse
    {
        $this->denyUnlessStaff();
        $this->captureAddress($id);
        $this->checkToken($request);
        $this->act(function () use ($id): null {
            $this->own->revoke($id);

            return null;
        }, 'received_documents.capture.flash.revoked');

        return $this->redirectToRoute(self::ROUTE, status: Response::HTTP_SEE_OTHER);
    }

    /**
     * The person's own received-documents address when it has this id; any
     * other id, an address of another purpose or person included, is not found.
     */
    private function captureAddress(string $id): OwnAddress
    {
        $address = $this->own->mine(ReceivedDocumentsPurpose::KEY);
        if (!$address instanceof OwnAddress || $address->id !== $id) {
            throw $this->createNotFoundException();
        }

        return $address;
    }

    /**
     * A new address with its label, or a new label for the one there is: a dialog over the settings.
     */
    private function labelForm(Request $request, ?OwnAddress $address, string $label): Response
    {
        $error = null;
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            $label = trim($request->request->getString('label'));
            try {
                if ($address instanceof OwnAddress) {
                    $this->own->rename($address->id, $label);
                } else {
                    $this->own->create(ReceivedDocumentsPurpose::KEY, $label);
                }
                $this->addFlash('success', $this->translator->trans($address instanceof OwnAddress ? 'received_documents.capture.flash.renamed' : 'received_documents.capture.flash.created'));

                return $this->redirectToRoute(self::ROUTE, status: Response::HTTP_SEE_OTHER);
            } catch (Refused $refused) {
                $error = $this->refusal($refused);
            }
        }

        return $this->render('@ReceivedDocuments/settings/capture_address.html.twig', [
            'address' => $address,
            'label' => $label,
            'error' => $error,
            'action' => $address instanceof OwnAddress ? $this->generateUrl('app_capture_address_rename', ['id' => $address->id]) : $this->generateUrl('app_capture_address_new'),
        ], new Response(status: null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /**
     * @param \Closure(): mixed $write
     */
    private function act(\Closure $write, string $flash): void
    {
        try {
            $write();
            $this->addFlash('success', $this->translator->trans($flash));
        } catch (Refused $refused) {
            $this->addFlash('error', $this->refusal($refused));
        }
    }

    /**
     * Mail's refusal in the words of this page; its own message names API tools.
     */
    private function refusal(Refused $refused): string
    {
        $key = 'received_documents.capture.refused.'.$refused->reason();
        $text = $this->translator->trans($key);

        return $text === $key ? $this->translator->trans('received_documents.capture.refused.other') : $text;
    }

    private function unavailable(): ?string
    {
        return match ($this->issuer->unavailable(ReceivedDocumentsPurpose::KEY)) {
            AddressUnavailable::NotConfigured => $this->translator->trans('received_documents.capture.refused.inbound_mail_not_configured'),
            AddressUnavailable::NoKey => $this->translator->trans('received_documents.capture.refused.inbound_mail_no_key'),
            null => null,
        };
    }

    /**
     * A row of Ui:DataTable. A mail that made drafts opens its first one.
     *
     * @return array<string, mixed>
     */
    private function recentRow(RecentMail $mail, WorkingState $working): array
    {
        return [
            'id' => $mail->messageId,
            'href' => null === $mail->firstDraftId ? null : $this->generateUrl('app_received_document', ['id' => $mail->firstDraftId]),
            'label' => $mail->receivedAt->setTimezone($this->clock->timezone())->format($this->translator->trans('format.datetime')),
            'cells' => $working->display->cells($mail),
        ];
    }

    /**
     * The value of a field of the list on a mail, as RowConditions compares it.
     */
    private function recentValue(RecentMail $mail, string $field): mixed
    {
        return match ($field) {
            CaptureMailLists::FROM => $mail->from ?? '',
            CaptureMailLists::SUBJECT => $mail->subject ?? '',
            CaptureMailLists::OUTCOME => $mail->outcome->value,
            default => null,
        };
    }

    private function denyUnlessStaff(): void
    {
        if (!$this->staff->isStaff()) {
            throw new AccessDeniedHttpException('Only the owners and members of the organisation keep the settings of received documents.');
        }
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
