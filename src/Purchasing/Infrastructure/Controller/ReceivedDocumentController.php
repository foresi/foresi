<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Infrastructure\Controller;

use Foresi\Attachment\Domain\Attachment;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Purchasing\Application\DeleteReceivedDocumentDraft;
use Foresi\Purchasing\Application\EditReceivedDocument;
use Foresi\Purchasing\Application\Mail\MailSource;
use Foresi\Purchasing\Application\Mail\MailSources;
use Foresi\Purchasing\Application\Query\ReceivedDocumentDetail;
use Foresi\Purchasing\Application\Query\ReceivedDocumentDetails;
use Foresi\Purchasing\Application\Query\ReceivedDocumentHistoryEntry;
use Foresi\Purchasing\Application\Query\ReceivedDocumentRow;
use Foresi\Purchasing\Application\Reading\AcceptReadValues;
use Foresi\Purchasing\Application\Reading\ReadAgain;
use Foresi\Purchasing\Application\RecordReceivedDocument;
use Foresi\Purchasing\Application\RestoreReceivedDocument;
use Foresi\Purchasing\Application\SavedReceivedDocument;
use Foresi\Purchasing\Application\TrashReceivedDocument;
use Foresi\Purchasing\Contract\Reading\ReadField;
use Foresi\Purchasing\Domain\ReceivedDocument;
use Foresi\Purchasing\Domain\ReceivedDocumentKind;
use Foresi\Purchasing\Domain\ReceivedDocumentRefused;
use Foresi\Purchasing\Infrastructure\Form\ReadingScreen;
use Foresi\Purchasing\Infrastructure\Form\ReceivedDocumentForm;
use Foresi\Purchasing\Infrastructure\Form\ReceivedDocumentScreen;
use Foresi\Purchasing\Infrastructure\Form\RefusalFields;
use Foresi\Purchasing\Infrastructure\Navigation\PurchasingNavigation;
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
 * Over the values of a draft, the reading of its file says where it stands,
 * reloaded in its frame while it runs; a person reads the file again from the
 * page header and takes the values it left as suggestions.
 *
 * Who may do what is the use cases' and the query services' to decide.
 *
 * @phpstan-import-type Values from ReceivedDocumentForm
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
        private readonly ReadAgain $readingAgain,
        private readonly AcceptReadValues $accepting,
        private readonly ReadingScreen $reading,
        private readonly MailSources $mailSources,
        private readonly ReceivedDocumentForm $form,
        private readonly ReceivedDocumentScreen $screen,
        private readonly InstanceClock $clock,
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

        return $this->render('@Purchasing/trash.html.twig', [
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

    /**
     * The reading of the document's file alone, in its frame, which the page reloads while the file is read.
     */
    #[Route('/purchasing/{id}/reading', name: 'app_purchasing_reading', requirements: ['id' => Requirement::UUID_V7], methods: ['GET'])]
    public function reading(string $id): Response
    {
        [$document, $reading] = $this->details->reading($id);

        return $this->render('@Purchasing/_reading.html.twig', [
            'document' => $document,
            'reading' => $this->reading->of($document, $reading),
            'polled' => true,
        ]);
    }

    #[Route('/purchasing/{id}/read-again', name: 'app_purchasing_read_again', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function readAgain(string $id, Request $request): RedirectResponse
    {
        $this->checkToken($request);
        try {
            $this->readingAgain->readAgain($id);
            $this->addFlash('success', $this->translator->trans('purchasing.flash.reading_again'));
        } catch (ReceivedDocumentRefused $refused) {
            $this->addFlash('error', $this->screen->refusal($refused));
        }

        return $this->toDocument($id);
    }

    /**
     * Takes the values of the reading a person checked, at the version of the page they saw.
     */
    #[Route('/purchasing/{id}/accept-reading', name: 'app_purchasing_accept_reading', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function acceptReading(string $id, Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $fields = [];
        foreach ($request->request->all('fields') as $key) {
            $field = \is_string($key) ? ReadField::tryFrom($key) : null;
            if (null !== $field) {
                $fields[] = $field;
            }
        }
        try {
            return $this->saved($this->accepting->accept($id, $fields, $request->request->getInt('version')), 'purchasing.flash.saved');
        } catch (ReceivedDocumentRefused $refused) {
            $this->addFlash('error', $this->screen->refusal($refused));
        }

        return $this->toDocument($id);
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
     * @param Values                $values
     * @param array<string, string> $errors message by input id
     */
    private function page(ReceivedDocumentDetail $detail, array $values, array $errors, int $version, int $status): Response
    {
        $document = $detail->document;
        $file = $detail->original;
        $timezone = $this->clock->timezone();
        $format = $this->translator->trans('format.datetime');

        return $this->render('@Purchasing/show.html.twig', [
            'document' => $document,
            'title' => $this->screen->title($document),
            'badge' => $this->screen->badge($document->isTrashed(), $document->isRecorded()),
            'values' => $values,
            'version' => $version,
            'errors' => $errors,
            'summary' => $this->screen->summary($errors),
            'stale' => isset($errors[RefusalFields::FORM]) && $this->translator->trans('purchasing.refused.'.ReceivedDocumentRefused::STALE) === $errors[RefusalFields::FORM],
            'editable' => !$document->isTrashed(),
            'reading' => $this->reading->of($document, $detail->reading),
            'mailSource' => $this->mailSource($document),
            'kinds' => $this->screen->kinds(),
            'treatments' => $this->screen->treatments(),
            'currencies' => $this->screen->currencies(),
            'correctable' => ReceivedDocumentKind::CreditNote->value === $values['kind'] ? $this->screen->correctable(!$detail->corrected instanceof ReceivedDocumentRow || \in_array($detail->corrected, $detail->correctable, true) ? $detail->correctable : [$detail->corrected, ...$detail->correctable]) : [],
            'corrected' => $detail->corrected instanceof ReceivedDocumentRow ? $this->screen->label($detail->corrected) : null,
            'figures' => $this->screen->figures($document),
            'lines' => $this->screen->lines($document),
            'lineRates' => $this->form->lineRates($values),
            'remaining' => $this->form->remaining($values),
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

    /**
     * The mail a draft was filed from, ready to show: the sender and the subject are the sender's text and the template escapes them.
     *
     * @return array{from: string, subject: string, date: string|null}|null
     */
    private function mailSource(ReceivedDocument $document): ?array
    {
        $mail = $this->mailSources->of($document);
        if (!$mail instanceof MailSource) {
            return null;
        }

        return [
            'from' => $mail->from,
            'subject' => $mail->subject,
            'date' => $mail->sentAt?->setTimezone($this->clock->timezone())->format($this->translator->trans('format.datetime')),
        ];
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(ReceivedDocumentListController::TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
