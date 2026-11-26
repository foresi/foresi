<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Catalog\Infrastructure\Controller;

use Foresi\Catalog\Application\ArchiveProductInput;
use Foresi\Catalog\Application\CatalogAccess;
use Foresi\Catalog\Application\CatalogRefused;
use Foresi\Catalog\Application\CreateProductInput;
use Foresi\Catalog\Application\ProductQueries;
use Foresi\Catalog\Application\ProductReads;
use Foresi\Catalog\Application\ProductWrites;
use Foresi\Catalog\Application\RestoreProductInput;
use Foresi\Catalog\Application\UpdateProductInput;
use Foresi\Catalog\Application\View\ProductLists;
use Foresi\Catalog\Contract\BillingKind;
use Foresi\Catalog\Contract\Field\ProductExtension;
use Foresi\Catalog\Domain\Product;
use Foresi\Catalog\Infrastructure\Column\ProductColumns;
use Foresi\Catalog\Infrastructure\Screen\ProductScreen;
use Foresi\Shared\Application\ActorRole;
use Foresi\Shared\Application\Field\FieldRefused;
use Foresi\Shared\Application\View\FilterRows;
use Foresi\Shared\Application\View\ListView;
use Foresi\Shared\Application\View\ListViews;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Application\View\ViewState;
use Foresi\Shared\Application\View\WorkingFilter;
use Foresi\Shared\Contract\RefusalKind;
use Foresi\Shared\Infrastructure\Http\TypedNumber;
use Foresi\Shared\Infrastructure\View\FilterSheet;
use Foresi\Ui\Column\RememberedDisplay;
use Foresi\Ui\Contract\Opens;
use Foresi\Ui\Contract\WorkingDisplay;
use Foresi\Ui\Contract\WorkingState;
use Foresi\Ui\Field\CustomFieldForm;
use Foresi\Ui\Field\CustomFieldSubmission;
use Foresi\Ui\View\ViewBar;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The products of the catalog, for the owners and members. The list runs on
 * saved views as the address book does: All, One-off, Recurring and Archived,
 * with the filter sheet, the person's own views and the Settings sheet. A row opens
 * the product as a record in the side sheet. A new product starts in the page
 * header and Edit in the product's, each in a dialog, where the billing kind
 * reveals the period fields (the product library of HubSpot and Pipedrive);
 * archive and restore are in the product's header menu. Without JavaScript
 * every dialog is a page of its own. The custom fields of a product (ADR 0045)
 * are in its form, under "Additional details", and on its detail.
 */
final class ProductController extends AbstractController
{
    public const string ROUTE = 'app_catalog_products';
    public const string PRODUCT_ROUTE = 'app_catalog_product';
    public const string TOKEN = 'catalog';

    /** The list's one sort, by name; "-name" backwards. */
    private const string SORT_NAME = 'name';
    private const int MAX_SEARCH = 100;

    public function __construct(
        private readonly ProductLists $productLists,
        private readonly ProductQueries $queries,
        private readonly ProductReads $reads,
        private readonly ProductWrites $writes,
        private readonly CatalogAccess $access,
        private readonly ProductScreen $screen,
        private readonly TranslatorInterface $translator,
        private readonly ListViews $listViews,
        private readonly ActorRole $actorRole,
        private readonly RememberedDisplay $rememberedDisplay,
        private readonly ViewBar $viewBar,
        private readonly FilterSheet $filterSheet,
        private readonly CustomFieldForm $customFields,
    ) {
    }

    #[Route('/catalog/products', name: self::ROUTE, methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyUnlessStaff();
        $input = $request->query;
        // The builder's rows (spec D11) become the list's working filter, in its address.
        if ($input->has(FilterRows::MARKER)) {
            return $this->redirectToRoute(self::ROUTE, $this->builtQuery($input), Response::HTTP_SEE_OTHER);
        }
        [$views, $view, $shown] = $this->views($input);
        $list = $this->productLists->list();
        $filterParam = WorkingFilter::param($shown, $view->filter);
        $text = trim(mb_substr($request->query->getString('q'), 0, self::MAX_SEARCH));
        // A view keeps its own order; the address names the sort only when it differs (spec D10).
        $viewSort = ViewState::sortParam($view->sort, self::SORT_NAME);
        $sort = $input->getString('sort', $viewSort);
        $sort = self::SORT_NAME === ltrim($sort, '-') ? $sort : self::SORT_NAME;
        $query = array_filter(['view' => $view->isAll() ? null : $view->key, 'q' => '' === $text ? null : $text, 'sort' => $viewSort === $sort ? null : $sort, WorkingFilter::PARAM => $filterParam], static fn (?string $value): bool => null !== $value);
        // The quick filters, columns and density changed in the Settings sheet, kept in the address next to the view and for the person (spec D10, D-display-3).
        $working = $this->rememberedDisplay->read(ProductColumns::LIST, [], $list->key, $view, $input->all(), filters: $list);
        $query += $working->query;
        $bar = $this->viewBar->of($list, $views, $view, $shown, $sort, self::SORT_NAME, $working, self::ROUTE, '' === $text ? [] : ['q' => $text]);

        return $this->render('@Catalog/products/index.html.twig', [
            'view' => $view->isAll() ? '' : $view->key,
            'emptyTitle' => $this->translator->trans(ProductLists::ARCHIVED === $view->key ? 'catalog.products.empty.archived_title' : 'catalog.products.empty.view_title'),
            'working' => $filterParam ?? '',
            ...$this->filterSheet->of($list, $shown, fn (ViewFilter $next): string => $this->generateUrl(self::ROUTE, [WorkingFilter::PARAM => WorkingFilter::param($next, $view->filter)] + array_diff_key($query, [WorkingFilter::PARAM => true])), [], pinned: $working->pinned),
            'sortKept' => $viewSort === $sort ? null : $sort,
            ...$bar,
            ...$this->tabs($views, $view->key, $query, $bar['viewBar']['modified']),
            'columns' => $working->display->table($sort, fn (string $next): string => $this->generateUrl(self::ROUTE, ['sort' => $next] + $query)),
            'rows' => array_map(fn (Product $product): array => $this->row($product, $working), $this->queries->index($shown, str_starts_with($sort, '-'), $text)),
            'density' => $working->tableDensity(),
            'picker' => $this->picker($working, $query),
            'workingQuery' => $working->query,
            'text' => $text,
            'filtered' => '' !== $text || null !== $filterParam,
            'resetHref' => $this->generateUrl(self::ROUTE, ['view' => $view->isAll() ? null : $view->key]),
        ]);
    }

    #[Route('/catalog/products/new', name: 'app_catalog_product_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->denyUnlessStaff();
        if (!$request->isMethod('POST')) {
            return $this->form(null, $this->screen->values(null), [], Response::HTTP_OK);
        }
        $this->checkToken($request);
        $values = $this->posted($request);
        $custom = $this->customFields->read(ProductExtension::RECORD, $request, true);
        if (!$custom->valid()) {
            return $this->form(null, $values, [], Response::HTTP_UNPROCESSABLE_ENTITY, $custom);
        }
        try {
            $d = $this->draft($values, $request->getLocale());
            $product = $this->writes->create(new CreateProductInput($d['name'], $d['billingKind'], $d['unit'], $d['unitPrice'], $d['currency'], $d['recurrenceUnit'], $d['interval'], $d['cycles'], $d['description']), $custom->parsed);
        } catch (CatalogRefused $refused) {
            $this->denyIfForbidden($refused);

            return $this->form(null, $values, $this->errors($refused), Response::HTTP_UNPROCESSABLE_ENTITY, $custom);
        } catch (FieldRefused $refused) {
            return $this->form(null, $values, [], Response::HTTP_UNPROCESSABLE_ENTITY, $custom->refused($refused->key, $this->translator->trans($refused->translationKey)));
        }
        $this->addFlash('success', $this->translator->trans('catalog.product.flash.created', ['name' => $product->name()]));

        return $this->redirectToRoute(self::PRODUCT_ROUTE, ['id' => $product->id()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/catalog/products/{id}', name: self::PRODUCT_ROUTE, requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function show(string $id): Response
    {
        $product = $this->product($id);

        return $this->render('@Catalog/products/show.html.twig', [
            'product' => $product,
            'details' => $this->screen->details($product),
        ]);
    }

    #[Route('/catalog/products/{id}/edit', name: 'app_catalog_product_edit', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request): Response
    {
        $product = $this->product($id);
        if (!$request->isMethod('POST')) {
            return $this->form($product, $this->screen->values($product), [], Response::HTTP_OK);
        }
        $this->checkToken($request);
        $values = $this->posted($request);
        $custom = $this->customFields->read(ProductExtension::RECORD, $request, false);
        if (!$custom->valid()) {
            return $this->form($product, $values, [], Response::HTTP_UNPROCESSABLE_ENTITY, $custom);
        }
        try {
            $d = $this->draft($values, $request->getLocale());
            $this->writes->update(new UpdateProductInput($id, $d['name'], $d['billingKind'], $d['unit'], $d['unitPrice'], $d['currency'], $d['recurrenceUnit'], $d['interval'], $d['cycles'], $d['description']), $custom->parsed);
        } catch (CatalogRefused $refused) {
            $this->denyIfForbidden($refused);

            return $this->form($product, $values, $this->errors($refused), Response::HTTP_UNPROCESSABLE_ENTITY, $custom);
        } catch (FieldRefused $refused) {
            return $this->form($product, $values, [], Response::HTTP_UNPROCESSABLE_ENTITY, $custom->refused($refused->key, $this->translator->trans($refused->translationKey)));
        }
        $this->addFlash('success', $this->translator->trans('catalog.product.flash.saved'));

        return $this->redirectToRoute(self::PRODUCT_ROUTE, ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    #[Route('/catalog/products/{id}/archive', name: 'app_catalog_product_archive', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function archive(string $id, Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $this->act(fn (): Product => $this->writes->archive(new ArchiveProductInput($id)));
        $this->addFlash('success', $this->translator->trans('catalog.product.flash.archived'));

        return $this->redirectToRoute(self::PRODUCT_ROUTE, ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    #[Route('/catalog/products/{id}/restore', name: 'app_catalog_product_restore', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function restore(string $id, Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $this->act(fn (): Product => $this->writes->restore(new RestoreProductInput($id)));
        $this->addFlash('success', $this->translator->trans('catalog.product.flash.restored'));

        return $this->redirectToRoute(self::PRODUCT_ROUTE, ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    /**
     * @param \Closure(): Product $write
     */
    private function act(\Closure $write): void
    {
        try {
            $write();
        } catch (CatalogRefused $refused) {
            $this->denyIfForbidden($refused);
            throw new NotFoundHttpException($refused->getMessage(), $refused);
        }
    }

    private function product(string $id): Product
    {
        try {
            return $this->reads->one($id);
        } catch (CatalogRefused $refused) {
            $this->denyIfForbidden($refused);
            throw new NotFoundHttpException($refused->getMessage(), $refused);
        }
    }

    /**
     * @return array<string, string>
     */
    private function posted(Request $request): array
    {
        $values = [];
        foreach (['name', 'unit', 'unitPrice', 'currency', 'billingKind', 'recurrenceUnit', 'interval', 'cycles'] as $field) {
            $values[$field] = trim($request->request->getString($field));
        }
        $values['description'] = $request->request->getString('description');

        return $values;
    }

    /**
     * The form's values as the use cases take them: a one-off or time-based
     * product sends no period, which the form hides but still sends without
     * JavaScript.
     *
     * @param array<string, string> $values
     *
     * @return array{name: string, description: string, unit: string, unitPrice: ?string, currency: string, billingKind: string, recurrenceUnit: ?string, interval: ?int, cycles: ?int}
     *
     * @throws CatalogRefused
     */
    private function draft(array $values, string $locale): array
    {
        $recurs = BillingKind::Recurring->value === $values['billingKind'];
        $interval = null;
        $cycles = null;
        if ($recurs) {
            if (1 !== preg_match('/^\d{1,2}$/', $values['interval'])) {
                throw CatalogRefused::invalid('interval_invalid', 'interval', 'The interval is a whole number from 1 to 99.');
            }
            $interval = (int) $values['interval'];
            if ('' !== $values['cycles']) {
                if (1 !== preg_match('/^\d{1,3}$/', $values['cycles'])) {
                    throw CatalogRefused::invalid('cycles_invalid', 'cycles', 'The cycles are a whole number from 1 to 999.');
                }
                $cycles = (int) $values['cycles'];
            }
        }
        $price = TypedNumber::decimal($values['unitPrice'], $locale);

        return [
            'name' => $values['name'],
            'description' => $values['description'],
            'unit' => $values['unit'],
            'unitPrice' => '' === $price ? null : $price,
            'currency' => $values['currency'],
            'billingKind' => $values['billingKind'],
            'recurrenceUnit' => $recurs && '' !== $values['recurrenceUnit'] ? $values['recurrenceUnit'] : null,
            'interval' => $interval,
            'cycles' => $cycles,
        ];
    }

    /**
     * @return array<string, string> the message of the refused field, by form field
     */
    private function errors(CatalogRefused $refused): array
    {
        $field = match ($refused->field()) {
            'name', 'description', 'unit', 'unitPrice', 'currency', 'billingKind', 'recurrenceUnit', 'interval', 'cycles' => $refused->field(),
            default => 'name',
        };

        return [$field => $this->translator->trans('catalog.product.error.'.$refused->reason())];
    }

    /**
     * @param array<string, string>      $values
     * @param array<string, string>      $errors
     * @param CustomFieldSubmission|null $custom the custom fields posted; null shows the product's own
     */
    private function form(?Product $product, array $values, array $errors, int $status, ?CustomFieldSubmission $custom = null): Response
    {
        return $this->render('@Catalog/products/form.html.twig', [
            'product' => $product,
            'action' => $product instanceof Product ? $this->generateUrl('app_catalog_product_edit', ['id' => $product->id()]) : $this->generateUrl('app_catalog_product_new'),
            'values' => $values,
            'errors' => $errors,
            'customValues' => $custom?->values,
            'customErrors' => $custom->errors ?? [],
        ] + $this->screen->options(), new Response(status: $status));
    }

    /**
     * The list's views as its address names them (spec D10): the person's views, the one
     * ?view= names, All when none, and the filter the list shows, the view's own unless f=
     * changed it.
     *
     * @param InputBag<string> $input
     *
     * @return array{list<ListView>, ListView, ViewFilter}
     */
    private function views(InputBag $input): array
    {
        $list = $this->productLists->list();
        $views = $this->listViews->of($list, $this->actorRole->userId());
        $view = ListViews::chosen($views, $input->getString('view'));

        return [$views, $view, WorkingFilter::decode($input->getString(WorkingFilter::PARAM), $list) ?? $view->filter];
    }

    /**
     * The list's address after the builder's Apply: what the sheet sent, its rows read
     * into the working filter, which is left out when it is the view's own.
     *
     * @param InputBag<string> $input
     *
     * @return array<string, mixed>
     */
    private function builtQuery(InputBag $input): array
    {
        [, $view] = $this->views($input);
        $query = array_diff_key($input->all(), [FilterRows::PARAM => true, FilterRows::MARKER => true, WorkingFilter::PARAM => true, 'view' => true]);

        return array_filter($query + ['view' => $view->isAll() ? null : $view->key, WorkingFilter::PARAM => WorkingFilter::param(FilterRows::parse($input->all(FilterRows::PARAM), $this->productLists->list()), $view->filter)], static fn (mixed $value): bool => null !== $value && '' !== $value);
    }

    /**
     * The tabs of the list and the other views of its Settings sheet (spec D7): All and the primary views
     * as tabs, the other views in the menu. The list counts none (D9).
     *
     * @param list<ListView>        $views
     * @param array<string, string> $query
     *
     * @return array{tabs: list<array<string, mixed>>, moreViews: list<array<string, mixed>>}
     */
    private function tabs(array $views, string $active, array $query, bool $modified): array
    {
        [$primary, $overflow] = ListViews::placed($views);
        $link = fn (ListView $view): array => [
            'label' => $view->translated ? $this->translator->trans($view->name) : $view->name,
            // A view opens with its own filter, sort and columns: the changed ones belong to the view they were changed on (spec D10).
            'href' => $this->generateUrl(self::ROUTE, ['view' => $view->isAll() ? null : $view->key] + array_diff_key($query, ['view' => true, 'sort' => true, WorkingFilter::PARAM => true] + array_flip(WorkingDisplay::KEYS))),
            'active' => $view->key === $active,
            'modified' => $modified && $view->key === $active,
            'private' => $view->private,
        ];

        return ['tabs' => array_map($link, $primary), 'moreViews' => array_map($link, $overflow)];
    }

    /**
     * The Settings sheet: what it keeps of the list, and the list without the changed columns.
     *
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
            'resetHref' => $this->generateUrl(self::ROUTE, $kept + [WorkingDisplay::DENSITY => '']),
            'modified' => $working->modified,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Product $product, WorkingState $working): array
    {
        return [
            'id' => $product->id(),
            'href' => $this->generateUrl(self::PRODUCT_ROUTE, ['id' => $product->id()]),
            // A product is a record: it opens in the side sheet (docs/design/overlays.md).
            'opens' => Opens::SHEET,
            'label' => $product->name(),
            'secondary' => implode(' · ', array_filter([$this->screen->price($product), $this->screen->billing($product)], static fn (string $part): bool => '' !== $part)),
            'cells' => $working->display->cells($product),
        ];
    }

    private function denyUnlessStaff(): void
    {
        if (!$this->access->mayWork()) {
            throw new AccessDeniedHttpException('Only the owners and members of the organisation work with its catalog.');
        }
    }

    private function denyIfForbidden(CatalogRefused $refused): void
    {
        if (RefusalKind::Forbidden === $refused->kind()) {
            throw new AccessDeniedHttpException($refused->getMessage(), $refused);
        }
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
