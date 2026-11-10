<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Infrastructure\Controller;

use App\Attachment\Application\AttachmentRefused;
use App\Attachment\Application\AttachmentUpload;
use App\Purchasing\Application\Query\ReceivedDocumentDetails;
use App\Purchasing\Application\Query\ReceivedDocumentFilter;
use App\Purchasing\Application\Query\ReceivedDocumentList;
use App\Purchasing\Application\Query\ReceivedDocumentPage;
use App\Purchasing\Application\Query\ReceivedDocumentRow;
use App\Purchasing\Application\Query\SupplierChoices;
use App\Purchasing\Application\Query\SupplierOption;
use App\Purchasing\Application\RecordReceivedDocument;
use App\Purchasing\Application\UploadedDocument;
use App\Purchasing\Application\UploadReceivedDocuments;
use App\Purchasing\Domain\PurchaseTaxTreatment;
use App\Purchasing\Domain\ReceivedDocumentKind;
use App\Purchasing\Domain\ReceivedDocumentRefused;
use App\Purchasing\Infrastructure\Form\PurchasingText;
use App\Purchasing\Infrastructure\Form\ReceivedDocumentForm;
use App\Purchasing\Infrastructure\Form\ReceivedDocumentScreen;
use App\Purchasing\Infrastructure\Form\RefusalFields;
use App\Purchasing\Infrastructure\Navigation\PurchasingNavigation;
use App\Shared\Domain\Money;
use App\Tenancy\Application\TenantClock;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The list of received documents, as Fakturoid lists its costs and Xero its
 * bills: the drafts to complete first, then the recorded ones and the trash,
 * filtered by firm, month, supplier, kind and treatment, and found by the
 * supplier's name or either number. Every row opens the document.
 *
 * Uploading and a new document start in the page header and open a dialog,
 * each also a page of its own without JavaScript. Files are dropped or chosen
 * as the files of a task are (the improved file upload of GOV.UK): each goes on
 * its own and becomes a draft; without JavaScript one form sends them all. A
 * new document without a file is recorded at once from the same fields as the
 * page of a document, and the dialog then leads to it.
 *
 * Who may do what is the use cases' and the query services' to decide.
 */
final class ReceivedDocumentListController extends AbstractController
{
    public const string TOKEN = 'purchasing';

    public function __construct(
        private readonly ReceivedDocumentList $list,
        private readonly ReceivedDocumentDetails $details,
        private readonly UploadReceivedDocuments $uploads,
        private readonly RecordReceivedDocument $recording,
        private readonly SupplierChoices $suppliers,
        private readonly ReceivedDocumentForm $form,
        private readonly ReceivedDocumentScreen $screen,
        private readonly PurchasingText $text,
        private readonly TenantClock $clock,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/purchasing', name: PurchasingNavigation::ROUTE, methods: ['GET'])]
    public function index(Request $request): Response
    {
        $query = $request->query;
        $tab = $query->getString('tab', ReceivedDocumentFilter::DRAFTS);
        $page = $this->list->page(new ReceivedDocumentFilter(
            \in_array($tab, ReceivedDocumentFilter::TABS, true) ? $tab : ReceivedDocumentFilter::DRAFTS,
            $this->optional($query->getString('firm')),
            ReceivedDocumentFilter::monthOf($query->getString('month')),
            $this->optional($query->getString('supplier')),
            ReceivedDocumentKind::tryFrom($query->getString('kind')),
            PurchaseTaxTreatment::tryFrom($query->getString('treatment')),
            $query->getString('q'),
            max(1, $query->getInt('page', 1)),
        ));
        $filter = $page->filter;
        $kept = array_filter([
            'tab' => ReceivedDocumentFilter::DRAFTS === $filter->tab ? null : $filter->tab,
            'firm' => $filter->firmId,
            'month' => $filter->month,
            'supplier' => $filter->supplierId,
            'kind' => $filter->kind?->value,
            'treatment' => $filter->treatment?->value,
            'q' => '' === $filter->text ? null : $filter->text,
        ], static fn (?string $value): bool => null !== $value);
        $severalFirms = \count($page->firms) > 1;
        $firms = $this->list->activeFirms();

        return $this->render('purchasing/index.html.twig', [
            'page' => $page,
            'filter' => $filter,
            'noFirm' => [] === $firms,
            'tabs' => $this->tabs($page, $kept),
            'columns' => $this->columns($filter->tab, $severalFirms),
            'rows' => array_map(fn (ReceivedDocumentRow $row): array => $this->row($row, $severalFirms), $page->rows),
            'firms' => $severalFirms ? ['' => $this->translator->trans('purchasing.list.all_firms')] + $page->firms : [],
            'months' => ['' => $this->translator->trans('purchasing.list.all_months')] + $this->screen->months([...$page->months, ...(null === $filter->month ? [] : [$filter->month])]),
            'suppliers' => ['' => $this->translator->trans('purchasing.list.all_suppliers')] + $page->suppliers,
            'kinds' => ['' => $this->translator->trans('purchasing.list.all_kinds')] + $this->screen->kinds(),
            'treatments' => ['' => $this->translator->trans('purchasing.list.all_treatments')] + $this->screen->treatments(),
            'baseUrl' => $this->generateUrl(PurchasingNavigation::ROUTE, $kept),
            'filtered' => [] !== array_diff_key($kept, ['tab' => true]),
        ]);
    }

    /**
     * One file a request from the dialog, answered in JSON; the plain form sends them all at once.
     */
    #[Route('/purchasing/upload', name: 'app_purchasing_upload', methods: ['GET', 'POST'])]
    public function upload(Request $request): Response
    {
        $firms = $this->list->activeFirms();
        if (!$request->isMethod('POST')) {
            return $this->render('purchasing/upload.html.twig', ['firms' => $firms, 'firm' => (string) array_key_first($firms)]);
        }
        $json = 'json' === $request->getPreferredFormat();
        // Larger than the web server takes: the body was dropped, the token with it.
        if (0 === $request->request->count() && 0 === $request->files->count() && $request->server->getInt('CONTENT_LENGTH') > 0) {
            return $this->uploadRefused(AttachmentRefused::TOO_BIG, $json);
        }
        $this->checkToken($request);
        $files = $this->files($request);
        if ([] === $files) {
            return $this->uploadRefused(AttachmentRefused::EMPTY, $json);
        }
        try {
            $uploaded = $this->uploads->upload($request->request->getString('firm'), $files);
        } catch (ReceivedDocumentRefused $refused) {
            return $this->uploadRefused(null, $json, $this->screen->refusal($refused));
        }
        if ($json) {
            $first = $uploaded[0];

            return $first->isDrafted()
                ? new JsonResponse(['id' => $first->documentId, 'url' => $this->generateUrl('app_purchasing_document', ['id' => $first->documentId]), 'warnings' => $this->screen->warnings($first->warnings)], Response::HTTP_CREATED)
                : new JsonResponse(['error' => $this->translator->trans('attachment.error.'.$first->refusal)], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $drafted = array_values(array_filter($uploaded, static fn (UploadedDocument $file): bool => $file->isDrafted()));
        if ([] !== $drafted) {
            $this->addFlash('success', $this->translator->trans('purchasing.flash.uploaded', ['count' => \count($drafted)]));
        }
        foreach ($uploaded as $file) {
            if (!$file->isDrafted()) {
                $this->addFlash('error', $file->fileName.': '.$this->translator->trans('attachment.error.'.$file->refusal));
            }
            foreach ($this->screen->warnings($file->warnings) as $warning) {
                $this->addFlash('warning', $file->fileName.': '.$warning);
            }
        }

        return $this->redirectToRoute(PurchasingNavigation::ROUTE, [], Response::HTTP_SEE_OTHER);
    }

    /**
     * A document typed in without a file, recorded at once; the dialog then leads to it.
     */
    #[Route('/purchasing/new', name: 'app_purchasing_new', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        $firms = $this->list->activeFirms();
        if (!$request->isMethod('POST')) {
            $firm = (string) array_key_first($firms);

            return $this->newForm($firms, $firm, $this->form->blank($this->clock->today()), [], Response::HTTP_OK);
        }
        $this->checkToken($request);
        $firm = $request->request->getString('firm');
        $values = $this->form->posted($request);
        $action = $request->request->getString('_action');
        if ('record' !== $action) {
            return $this->newForm($firms, $firm, $this->form->changed($values, $action), [], Response::HTTP_OK);
        }
        [$input, $errors] = $this->form->input($values);
        $errors = array_map($this->translator->trans(...), $errors);
        if (null !== $input) {
            try {
                $saved = $this->recording->create($firm, $input);
                $this->addFlash('success', $this->translator->trans('purchasing.flash.recorded', ['number' => $saved->document->evidenceNumber() ?? '']));
                foreach ($this->screen->warnings($saved->warnings) as $warning) {
                    $this->addFlash('warning', $warning);
                }

                return $this->redirectToRoute('app_purchasing_document', ['id' => $saved->document->id()], Response::HTTP_SEE_OTHER);
            } catch (ReceivedDocumentRefused $refused) {
                $errors[RefusalFields::of($refused->reason)] = $this->screen->refusal($refused);
            }
        }

        return $this->newForm($firms, $firm, $values, $errors, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * The contacts for the combobox of the form: found by text, or the one with an id,
     * which the form asks for once a contact was created in the dialog.
     */
    #[Route('/purchasing/suppliers', name: 'app_purchasing_suppliers', methods: ['GET'])]
    public function suppliers(Request $request): JsonResponse
    {
        $id = $request->query->getString('id');
        if ('' !== $id) {
            $one = $this->suppliers->find($id);
            $options = $one instanceof SupplierOption ? [$one] : [];
        } else {
            $options = $this->suppliers->search(mb_substr($request->query->getString('q'), 0, ReceivedDocumentFilter::MAX_TEXT));
        }

        return $this->json(['results' => array_map(static fn (SupplierOption $option): array => [
            'id' => $option->id,
            'label' => $option->name,
            'hint' => $option->registrationNumber ?? $option->vatId ?? '',
        ], $options)]);
    }

    /**
     * @param array<string, string> $firms
     * @param array<string, mixed>  $values
     * @param array<string, string> $errors
     */
    private function newForm(array $firms, string $firm, array $values, array $errors, int $status): Response
    {
        return $this->render('purchasing/new.html.twig', [
            'firms' => $firms,
            'firm' => $firm,
            'values' => $values,
            'errors' => $errors,
            'summary' => $this->screen->summary($errors),
            'kinds' => $this->screen->kinds(),
            'treatments' => $this->screen->treatments(),
            'currencies' => $this->screen->currencies(),
            'correctable' => isset($firms[$firm]) && ReceivedDocumentKind::CreditNote->value === $values['kind'] ? $this->screen->correctable($this->details->correctable($firm)) : [],
        ], new Response(status: $status));
    }

    /**
     * @return list<AttachmentUpload>
     */
    private function files(Request $request): array
    {
        $files = $request->files->get('file');
        $files = $files instanceof UploadedFile ? [$files] : array_values(array_filter($request->files->all('files'), static fn (mixed $file): bool => $file instanceof UploadedFile));

        return array_map(static fn (UploadedFile $file): AttachmentUpload => new AttachmentUpload(
            $file->getPathname(),
            $file->getClientOriginalName(),
            $file->isValid() ? (int) $file->getSize() : 0,
            $file->isValid(),
            \in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true),
        ), $files);
    }

    private function uploadRefused(?string $reason, bool $json, ?string $message = null): Response
    {
        $message ??= $this->translator->trans('attachment.error.'.$reason);
        if ($json) {
            return new JsonResponse(['error' => $message], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->addFlash('error', $message);

        return $this->redirectToRoute(PurchasingNavigation::ROUTE, [], Response::HTTP_SEE_OTHER);
    }

    /**
     * @param array<string, string> $kept
     *
     * @return list<array{label: string, href: string, count: int, active: bool}>
     */
    private function tabs(ReceivedDocumentPage $page, array $kept): array
    {
        $tabs = [];
        foreach ($page->tabCounts as $tab => $count) {
            $tabs[] = [
                'label' => $this->translator->trans('purchasing.list.tab.'.$tab),
                'href' => $this->generateUrl(PurchasingNavigation::ROUTE, ['tab' => ReceivedDocumentFilter::DRAFTS === $tab ? null : $tab] + $kept),
                'count' => $count,
                'active' => $tab === $page->filter->tab,
            ];
        }

        return $tabs;
    }

    /**
     * The numbers, the supplier and its number, the day that places the document in its month,
     * the total in its currency and the VAT in CZK; the treatment and the state where there is room.
     *
     * @return list<array<string, mixed>>
     */
    private function columns(string $tab, bool $severalFirms): array
    {
        $keys = ['number', ...($severalFirms ? ['firm'] : []), 'supplier', 'supplierNumber', 'monthDay', 'total', 'vatCzk', 'treatment', 'state'];

        return array_map(fn (string $key): array => [
            'key' => $key,
            'label' => $this->translator->trans('purchasing.list.column.'.(ReceivedDocumentFilter::DRAFTS === $tab && 'number' === $key ? 'draft' : $key)),
            'align' => \in_array($key, ['total', 'vatCzk'], true) ? 'right' : 'left',
            'phone' => \in_array($key, ['number', 'total'], true),
            'truncate' => \in_array($key, ['firm', 'supplier'], true),
            'wide' => \in_array($key, ['treatment', 'supplierNumber'], true),
        ], $keys);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(ReceivedDocumentRow $row, bool $severalFirms): array
    {
        $date = $this->translator->trans('format.date');
        $label = $row->evidenceNumber ?? $this->translator->trans('purchasing.list.draft_label', ['date' => $row->createdAt->setTimezone($this->clock->timezone())->format($date)]);
        $badge = $this->screen->badge($row->isTrashed(), null !== $row->evidenceNumber);
        $total = $row->total instanceof Money ? $this->text->amount($row->total) : '';
        $cells = [
            'number' => $label,
            'supplier' => $row->supplierName,
            'supplierNumber' => $row->supplierNumber,
            'monthDay' => $row->monthDay()?->format($date) ?? '',
            'total' => $total,
            'vatCzk' => $row->total instanceof Money && $row->vatCzk instanceof Money ? $this->text->amount($row->vatCzk) : '',
            'treatment' => $this->translator->trans('purchasing.treatment.'.$row->treatment->value),
            'state' => ['text' => $badge['label'], 'tone' => $badge['tone']] + ($row->isTrashed() && null !== $row->deletedReason ? ['hint' => $row->deletedReason] : []),
        ];
        if ($severalFirms) {
            $cells['firm'] = $row->firmName;
        }

        return [
            'id' => $row->id,
            'href' => $this->generateUrl('app_purchasing_document', ['id' => $row->id]),
            'plain' => true,
            'label' => $label,
            'secondary' => implode(' · ', array_filter([$row->supplierName, $total, $badge['label']], static fn (string $part): bool => '' !== $part)),
            'cells' => $cells,
        ];
    }

    private function optional(string $value): ?string
    {
        return '' === $value ? null : $value;
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
