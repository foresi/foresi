<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Infrastructure\Controller;

use App\Attachment\Domain\Attachment;
use App\Purchasing\Application\DeleteReceivedDocumentDraft;
use App\Purchasing\Application\EditReceivedDocument;
use App\Purchasing\Application\Query\ReceivedDocumentDetail;
use App\Purchasing\Application\Query\ReceivedDocumentDetails;
use App\Purchasing\Application\Query\ReceivedDocumentHistoryEntry;
use App\Purchasing\Application\Query\ReceivedDocumentRow;
use App\Purchasing\Application\RecordReceivedDocument;
use App\Purchasing\Application\RestoreReceivedDocument;
use App\Purchasing\Application\SavedReceivedDocument;
use App\Purchasing\Application\TrashReceivedDocument;
use App\Purchasing\Domain\ReceivedDocument;
use App\Purchasing\Domain\ReceivedDocumentKind;
use App\Purchasing\Domain\ReceivedDocumentRefused;
use App\Purchasing\Infrastructure\Form\ReceivedDocumentForm;
use App\Purchasing\Infrastructure\Form\ReceivedDocumentScreen;
use App\Purchasing\Infrastructure\Form\RefusalFields;
use App\Purchasing\Infrastructure\Navigation\PurchasingNavigation;
use App\Tenancy\Application\TenantClock;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A received document on one page, as iDoklad and Xero show a bill: the
 * original file beside its values, which a draft is completed and recorded
 * with and a recorded document is changed with, and its history under them.
 * Recording, saving, the trash, a restore and deleting a draft sit in the
 * page header. A save that went through tells its warnings, which never stop
 * it; a refusal is told next to its field and in the summary over the save,
 * as on the invoice form. The trash asks for a reason in a dialog, as it does
 * for an invoice; deleting a draft asks first.
 *
 * Who may do what is the use cases' and the query services' to decide.
 */
final class ReceivedDocumentController extends AbstractController
{
    /** The images the browser shows from the attachment store, besides a PDF (AttachmentController::INLINE_TYPES). */
    private const array IMAGES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    public function __construct(
        private readonly ReceivedDocumentDetails $details,
        private readonly RecordReceivedDocument $recording,
        private readonly EditReceivedDocument $editing,
        private readonly TrashReceivedDocument $trashing,
        private readonly RestoreReceivedDocument $restoring,
        private readonly DeleteReceivedDocumentDraft $deleting,
        private readonly ReceivedDocumentForm $form,
        private readonly ReceivedDocumentScreen $screen,
        private readonly TenantClock $clock,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/purchasing/{id}', name: 'app_purchasing_document', requirements: ['id' => Requirement::UUID_V7], methods: ['GET', 'POST'])]
    public function show(string $id, Request $request): Response
    {
        $detail = $this->details->of($id);
        $document = $detail->document;
        if (!$request->isMethod('POST')) {
            return $this->page($detail, $this->form->of($document), [], $document->version(), Response::HTTP_OK);
        }
        $this->checkToken($request);
        $values = $this->form->posted($request);
        $version = $request->request->getInt('version');
        $action = $request->request->getString('_action');
        if (!\in_array($action, ['save', 'record'], true)) {
            return $this->page($detail, $this->form->changed($values, $action), [], $version, Response::HTTP_OK);
        }
        [$input, $errors] = $this->form->input($values);
        $errors = array_map($this->translator->trans(...), $errors);
        if (null !== $input) {
            try {
                $saved = 'record' === $action && !$document->isRecorded()
                    ? $this->recording->record($id, $input, $version)
                    : $this->editing->edit($id, $input, $version);

                return $this->saved($saved, 'record' === $action && !$document->isRecorded() ? 'purchasing.flash.recorded' : 'purchasing.flash.saved');
            } catch (ReceivedDocumentRefused $refused) {
                $errors[RefusalFields::of($refused->reason)] = $this->screen->refusal($refused);
            }
        }

        return $this->page($this->details->of($id), $values, $errors, $version, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Route('/purchasing/{id}/trash', name: 'app_purchasing_trash', requirements: ['id' => Requirement::UUID_V7], methods: ['GET', 'POST'])]
    public function trash(string $id, Request $request): Response
    {
        $detail = $this->details->of($id);
        $reason = '';
        $error = null;
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            $reason = $request->request->getString('reason');
            try {
                $this->trashing->trash($id, $reason);
                $this->addFlash('success', $this->translator->trans('purchasing.flash.trashed', ['number' => $this->screen->title($detail->document)]));

                return $this->toDocument($id);
            } catch (ReceivedDocumentRefused $refused) {
                if ('reason' !== RefusalFields::of($refused->reason)) {
                    $this->addFlash('error', $this->screen->refusal($refused));

                    return $this->toDocument($id);
                }
                $error = $this->screen->refusal($refused);
            }
        }

        return $this->render('purchasing/trash.html.twig', [
            'document' => $detail->document,
            'title' => $this->screen->title($detail->document),
            'reason' => $reason,
            'error' => $error,
            'maxReason' => ReceivedDocument::MAX_REASON_LENGTH,
        ], new Response(status: null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/purchasing/{id}/restore', name: 'app_purchasing_restore', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function restore(string $id, Request $request): RedirectResponse
    {
        $this->checkToken($request);
        try {
            $document = $this->restoring->restore($id);
            $this->addFlash('success', $this->translator->trans('purchasing.flash.restored', ['number' => $this->screen->title($document)]));
        } catch (ReceivedDocumentRefused $refused) {
            $this->addFlash('error', $this->screen->refusal($refused));
        }

        return $this->toDocument($id);
    }

    #[Route('/purchasing/{id}/delete', name: 'app_purchasing_delete', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function delete(string $id, Request $request): RedirectResponse
    {
        $this->checkToken($request);
        try {
            $this->deleting->delete($id);
        } catch (ReceivedDocumentRefused $refused) {
            $this->addFlash('error', $this->screen->refusal($refused));

            return $this->toDocument($id);
        }
        $this->addFlash('success', $this->translator->trans('purchasing.flash.deleted'));

        return $this->redirectToRoute(PurchasingNavigation::ROUTE, [], Response::HTTP_SEE_OTHER);
    }

    private function saved(SavedReceivedDocument $saved, string $message): RedirectResponse
    {
        $this->addFlash('success', $this->translator->trans($message, ['number' => $this->screen->title($saved->document)]));
        foreach ($this->screen->warnings($saved->warnings) as $warning) {
            $this->addFlash('warning', $warning);
        }

        return $this->toDocument($saved->document->id());
    }

    /**
     * @param array<string, mixed>  $values
     * @param array<string, string> $errors message by input id
     */
    private function page(ReceivedDocumentDetail $detail, array $values, array $errors, int $version, int $status): Response
    {
        $document = $detail->document;
        $file = $detail->original;
        $timezone = $this->clock->timezone();
        $format = $this->translator->trans('format.datetime');

        return $this->render('purchasing/show.html.twig', [
            'document' => $document,
            'title' => $this->screen->title($document),
            'badge' => $this->screen->badge($document->isTrashed(), $document->isRecorded()),
            'values' => $values,
            'version' => $version,
            'errors' => $errors,
            'summary' => $this->screen->summary($errors),
            'stale' => isset($errors[RefusalFields::FORM]) && $this->translator->trans('purchasing.refused.'.ReceivedDocumentRefused::STALE) === $errors[RefusalFields::FORM],
            'editable' => !$document->isTrashed(),
            'kinds' => $this->screen->kinds(),
            'treatments' => $this->screen->treatments(),
            'currencies' => $this->screen->currencies(),
            'correctable' => ReceivedDocumentKind::CreditNote->value === $values['kind'] ? $this->screen->correctable(!$detail->corrected instanceof ReceivedDocumentRow || \in_array($detail->corrected, $detail->correctable, true) ? $detail->correctable : [$detail->corrected, ...$detail->correctable]) : [],
            'corrected' => $detail->corrected instanceof ReceivedDocumentRow ? $this->screen->label($detail->corrected) : null,
            'figures' => $this->screen->figures($document),
            'rateDate' => $document->exchangeRateDate()?->format($this->translator->trans('format.date')),
            'deduction' => [
                'period' => $document->deductionPeriod()?->format('m/Y'),
                'latest' => $document->latestDeductionDate()?->format($this->translator->trans('format.date')),
            ],
            'file' => $file instanceof Attachment ? [
                'url' => $this->generateUrl('app_attachment', ['id' => $file->id()]),
                'name' => $file->fileName(),
                'preview' => match (true) {
                    'application/pdf' === $file->mediaType() => 'pdf',
                    \in_array($file->mediaType(), self::IMAGES, true) => 'image',
                    default => 'download',
                },
            ] : null,
            'timeline' => array_map(fn (ReceivedDocumentHistoryEntry $entry): array => [
                'time' => $entry->occurredAt->setTimezone($timezone)->format($format),
                'datetime' => $entry->occurredAt->format(\DATE_ATOM),
                'icon' => match ($entry->event) {
                    ReceivedDocumentHistoryEntry::CREATED => 'plus',
                    ReceivedDocumentHistoryEntry::RECORDED => 'circle-check',
                    ReceivedDocumentHistoryEntry::TRASHED => 'trash-2',
                    ReceivedDocumentHistoryEntry::RESTORED => 'archive-restore',
                    default => 'pencil',
                },
                'text' => $this->historyText($entry),
            ], $detail->history),
        ], new Response(status: $status));
    }

    private function historyText(ReceivedDocumentHistoryEntry $entry): string
    {
        $text = $this->translator->trans('purchasing.history.'.$entry->event, [
            'who' => $entry->userName ?? $this->translator->trans('purchasing.history.system'),
            'reason' => $entry->reason ?? '',
        ]);
        if ([] === $entry->fields) {
            return $text;
        }

        return $text.': '.implode(', ', array_map(fn (string $field): string => $this->translator->trans('purchasing.field.'.$field), $entry->fields));
    }

    private function toDocument(string $id): RedirectResponse
    {
        return $this->redirectToRoute('app_purchasing_document', ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(ReceivedDocumentListController::TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
