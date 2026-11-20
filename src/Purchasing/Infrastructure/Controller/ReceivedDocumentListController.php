<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Infrastructure\Controller;

use Foresi\Attachment\Application\AttachmentRefused;
use Foresi\Attachment\Application\AttachmentUpload;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Purchasing\Application\Query\ReceivedDocumentDetails;
use Foresi\Purchasing\Application\Query\ReceivedDocumentFilter;
use Foresi\Purchasing\Application\Query\ReceivedDocumentList;
use Foresi\Purchasing\Application\Query\ReceivedDocumentPage;
use Foresi\Purchasing\Application\Query\ReceivedDocumentRow;
use Foresi\Purchasing\Application\Query\SupplierChoices;
use Foresi\Purchasing\Application\Query\SupplierOption;
use Foresi\Purchasing\Application\RecordReceivedDocument;
use Foresi\Purchasing\Application\UploadedDocument;
use Foresi\Purchasing\Application\UploadReceivedDocuments;
use Foresi\Purchasing\Application\View\ReceivedDocumentLists;
use Foresi\Purchasing\Domain\ReceivedDocumentKind;
use Foresi\Purchasing\Domain\ReceivedDocumentRefused;
use Foresi\Purchasing\Infrastructure\Column\ReceivedDocumentColumns;
use Foresi\Purchasing\Infrastructure\Form\ReceivedDocumentForm;
use Foresi\Purchasing\Infrastructure\Form\ReceivedDocumentScreen;
use Foresi\Purchasing\Infrastructure\Form\RefusalFields;
use Foresi\Purchasing\Infrastructure\Navigation\PurchasingNavigation;
use Foresi\Purchasing\Infrastructure\View\ReceivedDocumentViews;
use Foresi\Shared\Application\View\FilterRows;
use Foresi\Shared\Application\View\ListView;
use Foresi\Shared\Application\View\ListViews;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Application\View\WorkingFilter;
use Foresi\Shared\Infrastructure\View\FilterSheet;
use Foresi\Ui\Column\RememberedDisplay;
use Foresi\Ui\Contract\Opens;
use Foresi\Ui\Contract\WorkingDisplay;
use Foresi\Ui\Contract\WorkingState;
use Foresi\Ui\View\ViewBar;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The list of received documents, as Fakturoid lists its costs and Xero its
 * bills, on saved views (spec D3, D7): All, the drafts to complete, the recorded
 * ones and the trash ship as views, with the person's own beside them. The month
 * is a chip on the bar; the supplier, the kind and the treatment are
 * conditions of "+ Filter" (D11). Found by the supplier's name or either number.
 * Every row opens the document.
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

    public function __construct(private readonly ReceivedDocumentLists $receivedLists,
        private readonly ReceivedDocumentList $list,
        private readonly ReceivedDocumentDetails $details,
        private readonly UploadReceivedDocuments $uploads,
        private readonly RecordReceivedDocument $recording,
        private readonly SupplierChoices $suppliers,
        private readonly ReceivedDocumentForm $form,
        private readonly ReceivedDocumentScreen $screen,
        private readonly InstanceClock $clock,
        private readonly TranslatorInterface $translator,
        private readonly ReceivedDocumentColumns $columns,
        private readonly ReceivedDocumentViews $receivedViews,
        private readonly RememberedDisplay $rememberedDisplay,
        private readonly ViewBar $viewBar,
        private readonly FilterSheet $filterSheet,
    ) {
    }

    #[Route('/purchasing', name: PurchasingNavigation::ROUTE, methods: ['GET'])]
    public function index(Request $request): Response
    {
        $input = $request->query;
        // The builder's rows (spec D11) become the list's working filter, in its address.
        if ($input->has(FilterRows::MARKER)) {
            return $this->redirectToRoute(PurchasingNavigation::ROUTE, $this->builtQuery($input), Response::HTTP_SEE_OTHER);
        }
        [$views, $view, $shown] = $this->receivedViews->of($input);
        $list = $this->receivedLists->list();
        $filterParam = WorkingFilter::param($shown, $view->filter);
        $page = $this->list->page(new ReceivedDocumentFilter(
            $this->tabOf($view, $shown),
            ReceivedDocumentFilter::monthOf($input->getString('month')),
            $input->getString('q'),
            max(1, $input->getInt('page', 1)),
            $shown,
            $this->counted($views),
        ));
        $filter = $page->filter;
        $query = array_filter([
            'view' => $view->isAll() ? null : $view->key,
            'month' => $filter->month,
            'q' => '' === $filter->text ? null : $filter->text,
            WorkingFilter::PARAM => $filterParam,
        ], static fn (?string $value): bool => null !== $value);
        $firm = $this->list->firmId();
        // The columns and density changed in the Columns sheet, kept in the address next to the view and for the person (spec D10, D-display-3).
        $working = $this->rememberedDisplay->read(ReceivedDocumentColumns::LIST, [], $list->key, $view, $input->all());
        $query += $working->query;
        $bar = $this->viewBar->of($list, $views, $view, $shown, '', '', $working, PurchasingNavigation::ROUTE, '' === $filter->text ? [] : ['q' => $filter->text]);

        return $this->render('@Purchasing/index.html.twig', [
            'page' => $page,
            'filter' => $filter,
            'noFirm' => null === $firm,
            'view' => $view->isAll() ? '' : $view->key,
            'working' => $filterParam ?? '',
            ...$this->filterSheet->of($list, $shown, fn (ViewFilter $next): string => $this->generateUrl(PurchasingNavigation::ROUTE, [WorkingFilter::PARAM => WorkingFilter::param($next, $view->filter)] + array_diff_key($query, [WorkingFilter::PARAM => true])), $this->references()),
            ...$bar,
            ...$this->tabs($views, $view->key, $page, $query, $bar['viewBar']['modified']),
            'columns' => $working->display->table(),
            'rows' => array_map(fn (ReceivedDocumentRow $row): array => $this->row($row, $working), $page->rows),
            'density' => $working->tableDensity(),
            'picker' => $this->picker($working, $query),
            'workingQuery' => $working->query,
            'months' => $this->monthOptions($page->months, $filter->month),
            'baseUrl' => $this->generateUrl(PurchasingNavigation::ROUTE, $query),
            'resetHref' => $this->generateUrl(PurchasingNavigation::ROUTE, ['view' => $view->isAll() ? null : $view->key]),
            'filtered' => [] !== array_diff_key($query, ['view' => true] + array_flip(WorkingDisplay::KEYS)),
        ]);
    }

    /**
     * One file a request from the dialog, answered in JSON; the plain form sends them all at once.
     */
    #[Route('/purchasing/upload', name: 'app_purchasing_upload', methods: ['GET', 'POST'])]
    public function upload(Request $request): Response
    {
        // The documents go to the instance's firm (ADR 0039); none before the first-run setup.
        $firm = $this->list->firmId() ?? '';
        if (!$request->isMethod('POST')) {
            return $this->render('@Purchasing/upload.html.twig');
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
        $uploaded = $this->uploads->upload($firm, $files);
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
        // The document goes to the instance's firm (ADR 0039); none before the first-run setup.
        $firm = $this->list->firmId() ?? '';
        if (!$request->isMethod('POST')) {
            return $this->newForm($firm, $this->form->blank($this->clock->today()), [], Response::HTTP_OK);
        }
        $this->checkToken($request);
        $values = $this->form->posted($request);
        $action = $request->request->getString('_action');
        if ('record' !== $action) {
            return $this->newForm($firm, $this->form->changed($values, $action), [], Response::HTTP_OK);
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

        return $this->newForm($firm, $values, $errors, Response::HTTP_UNPROCESSABLE_ENTITY);
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
     * @param array<string, mixed>  $values
     * @param array<string, string> $errors
     */
    private function newForm(string $firm, array $values, array $errors, int $status): Response
    {
        return $this->render('@Purchasing/new.html.twig', [
            'values' => $values,
            'errors' => $errors,
            'summary' => $this->screen->summary($errors),
            'kinds' => $this->screen->kinds(),
            'treatments' => $this->screen->treatments(),
            'currencies' => $this->screen->currencies(),
            'correctable' => '' !== $firm && ReceivedDocumentKind::CreditNote->value === $values['kind'] ? $this->screen->correctable($this->details->correctable($firm)) : [],
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

    private function uploadRefused(string $reason, bool $json): Response
    {
        $message = $this->translator->trans('attachment.error.'.$reason);
        if ($json) {
            return new JsonResponse(['error' => $message], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->addFlash('error', $message);

        return $this->redirectToRoute(PurchasingNavigation::ROUTE, [], Response::HTTP_SEE_OTHER);
    }

    /**
     * The tab a view stands for where the list still asks for one, for its order and its
     * empty state: the trash; a shipped view as it ships; anything else the whole list.
     */
    private function tabOf(ListView $view, ViewFilter $working): string
    {
        foreach ($working->on(ReceivedDocumentLists::TRASHED) as $condition) {
            if (true === $condition->value) {
                return ReceivedDocumentFilter::TRASH;
            }
        }
        // A view changed in the builder no longer says what its empty state says.
        if ($working->toArray() !== $view->filter->toArray()) {
            return ReceivedDocumentFilter::ALL;
        }

        return \in_array($view->slug, ReceivedDocumentFilter::TABS, true) ? $view->slug : ReceivedDocumentFilter::ALL;
    }

    /**
     * The views that show a count, by key: the primary tabs alone (spec D9).
     *
     * @param list<ListView> $views
     *
     * @return array<string, ViewFilter>
     */
    private function counted(array $views): array
    {
        $counted = [];
        foreach (ListViews::placed($views)[0] as $tab) {
            if ($tab->showCount) {
                $counted[$tab->key] = $tab->filter;
            }
        }

        return $counted;
    }

    /**
     * The list's address after the builder's Apply: what the sheet sent, its rows read
     * into the working filter, which is left out when it is the view's own. The old
     * parameters of the sheet are in the rows by then.
     *
     * @param InputBag<string> $input
     *
     * @return array<string, mixed>
     */
    private function builtQuery(InputBag $input): array
    {
        [, $view] = $this->receivedViews->of($input);
        $query = array_diff_key($input->all(), [FilterRows::PARAM => true, FilterRows::MARKER => true, WorkingFilter::PARAM => true, 'page' => true, 'view' => true, 'tab' => true] + ReceivedDocumentViews::OLD_PARAMS);

        return array_filter($query + ['view' => $view->isAll() ? null : $view->key, WorkingFilter::PARAM => WorkingFilter::param(FilterRows::parse($input->all(FilterRows::PARAM), $this->receivedLists->list()), $view->filter)], static fn (mixed $value): bool => null !== $value && '' !== $value);
    }

    /**
     * The contacts a supplier condition picks from and names: those the form of a document picks from.
     *
     * @return array<string, array{url: string, name: \Closure(string): ?string}>
     */
    private function references(): array
    {
        return [ReceivedDocumentLists::CONTACT => [
            'url' => $this->generateUrl('app_purchasing_suppliers'),
            'name' => fn (string $contact): ?string => $this->suppliers->find($contact)?->name,
        ]];
    }

    /**
     * The month chip (spec D5): the periods relative to today first, then the months
     * with a document, and the one asked for, though it has none.
     *
     * @param list<string> $months
     *
     * @return array<int|string, string|array<int|string, string>>
     */
    private function monthOptions(array $months, ?string $month): array
    {
        $options = ['' => $this->translator->trans('purchasing.list.all_months')];
        foreach (ReceivedDocumentFilter::RELATIVE_MONTHS as $period) {
            $options[$period] = $this->translator->trans('purchasing.list.month_period.'.$period);
        }
        $absolute = null === $month || \in_array($month, ReceivedDocumentFilter::RELATIVE_MONTHS, true) ? [] : [$month];
        $choices = $this->screen->months([...$months, ...$absolute]);

        return [] === $choices ? $options : $options + [$this->translator->trans('purchasing.list.month_period.by_month') => $choices];
    }

    /**
     * The tabs of the list and its "More views" menu (spec D7): All and the primary views
     * as tabs, with the count of those that show one; the other views in the menu.
     *
     * @param list<ListView>        $views
     * @param array<string, string> $query
     *
     * @return array{tabs: list<array<string, mixed>>, moreViews: list<array<string, mixed>>}
     */
    private function tabs(array $views, string $active, ReceivedDocumentPage $page, array $query, bool $modified): array
    {
        [$primary, $overflow] = ListViews::placed($views);
        $link = fn (ListView $view, bool $count): array => [
            'label' => $view->translated ? $this->translator->trans($view->name) : $view->name,
            // A view opens with its own filter and columns: the changed ones belong to the view they were changed on (spec D10).
            'href' => $this->generateUrl(PurchasingNavigation::ROUTE, ['view' => $view->isAll() ? null : $view->key] + array_diff_key($query, ['view' => true, 'page' => true, WorkingFilter::PARAM => true] + array_flip(WorkingDisplay::KEYS))),
            'count' => $count && $view->showCount ? ($page->counts[$view->key] ?? null) : null,
            'active' => $view->key === $active,
            'modified' => $modified && $view->key === $active,
            'private' => $view->private,
        ];

        return ['tabs' => array_map(static fn (ListView $view): array => $link($view, true), $primary), 'moreViews' => array_map(static fn (ListView $view): array => $link($view, false), $overflow)];
    }

    /**
     * @param array<string, string> $query
     *
     * @return array<string, mixed>
     */
    private function picker(WorkingState $working, array $query): array
    {
        $kept = array_diff_key($query, array_flip(WorkingDisplay::KEYS));

        return [
            'keep' => $kept,
            'columns' => $working->picker(),
            'density' => $working->density,
            // An empty density names the view's own display, which forgets the one remembered (D-display-3).
            'resetHref' => $this->generateUrl(PurchasingNavigation::ROUTE, $kept + [WorkingDisplay::DENSITY => '']),
            'modified' => $working->modified,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(ReceivedDocumentRow $row, WorkingState $working): array
    {
        $label = $this->columns->number($row);
        $badge = $this->columns->badge($row);

        return [
            'id' => $row->id,
            'href' => $this->generateUrl('app_purchasing_document', ['id' => $row->id]),
            'opens' => Opens::SHEET,
            'label' => $label,
            'secondary' => implode(' · ', array_filter([$row->supplierName, $this->columns->total($row), $badge['label']], static fn (string $part): bool => '' !== $part)),
            'cells' => $working->display->cells($row),
        ];
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
