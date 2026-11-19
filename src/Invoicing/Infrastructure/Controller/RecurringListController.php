<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Controller;

use Foresi\Identity\Application\OwnerCheck;
use Foresi\Identity\Application\StaffCheck;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Invoicing\Application\DeliverRecurringInvoice;
use Foresi\Invoicing\Application\IssueAfterTodaysRun;
use Foresi\Invoicing\Application\IssueProblem;
use Foresi\Invoicing\Application\IssueRecurringOccurrence;
use Foresi\Invoicing\Application\IssueRefused;
use Foresi\Invoicing\Application\PricedLine;
use Foresi\Invoicing\Application\RecurringAmounts;
use Foresi\Invoicing\Application\RecurringChangedMeanwhile;
use Foresi\Invoicing\Application\RecurringFilter;
use Foresi\Invoicing\Application\RecurringInvoices;
use Foresi\Invoicing\Application\RecurringLifecycle;
use Foresi\Invoicing\Application\RecurringOutcome;
use Foresi\Invoicing\Application\RecurringOverview;
use Foresi\Invoicing\Application\RecurringPage;
use Foresi\Invoicing\Application\RecurringRefused;
use Foresi\Invoicing\Application\UnknownRecurringInvoice;
use Foresi\Invoicing\Application\View\RecurringLists;
use Foresi\Invoicing\Contract\Percent;
use Foresi\Invoicing\Domain\DocumentTotals;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Invoicing\Domain\PeriodText;
use Foresi\Invoicing\Domain\RecurringInvoice;
use Foresi\Invoicing\Domain\RecurringState;
use Foresi\Invoicing\Infrastructure\Column\RecurringColumns;
use Foresi\Invoicing\Infrastructure\Column\RecurringRow;
use Foresi\Invoicing\Infrastructure\Form\AmountText;
use Foresi\Party\Application\LegalEntities;
use Foresi\Party\Application\TransactionalWork;
use Foresi\Shared\Application\ActorRole;
use Foresi\Shared\Application\View\FilterRows;
use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Shared\Application\View\ListView;
use Foresi\Shared\Application\View\ListViews;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Application\View\ViewState;
use Foresi\Shared\Application\View\WorkingFilter;
use Foresi\Shared\Domain\Money;
use Foresi\Shared\Domain\Schedule\RecurrenceEnd;
use Foresi\Shared\Infrastructure\View\FilterSheet;
use Foresi\Ui\Column\RememberedDisplay;
use Foresi\Ui\Contract\WorkingDisplay;
use Foresi\Ui\Contract\WorkingState;
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
 * The list of recurring templates, for staff, with tabs by state as the invoice list
 * has them. Pausing, resuming, ending, deleting and issuing the next invoice now are
 * short decisions, so each opens in a dialog over the list and comes back to it; each
 * is also a page of its own at the same URL, which works without JavaScript. The
 * dialog states what will happen before anything does: resume names the dates the
 * pause skips, issue now the dates the invoice gets.
 */
final class RecurringListController extends AbstractController
{
    public const string ACTION_TOKEN = 'recurring_action';
    /** The parts of the list's query an action carries back to it. */
    /** The views the list ships, whose empty state has words of its own. */
    private const array SHIPPED = [ListDefinition::ALL, 'active', 'paused', 'ended', 'failing'];
    private const array LIST_QUERY = ['view', 'tab', 'sort', 'page', WorkingFilter::PARAM, WorkingDisplay::COLUMNS, WorkingDisplay::DENSITY];

    public function __construct(private readonly RecurringLists $recurringLists,
        private readonly RecurringInvoices $templates,
        private readonly RecurringLifecycle $lifecycle,
        private readonly RecurringOverview $overview,
        private readonly RecurringAmounts $amounts,
        private readonly IssueRecurringOccurrence $issue,
        private readonly DeliverRecurringInvoice $delivery,
        private readonly IssueAfterTodaysRun $afterTodaysRun,
        private readonly TransactionalWork $work,
        private readonly LegalEntities $firms,
        private readonly AmountText $amountText,
        private readonly InstanceClock $clock,
        private readonly StaffCheck $staff,
        private readonly OwnerCheck $owner,
        private readonly TranslatorInterface $translator,
        private readonly ListViews $listViews,
        private readonly ActorRole $actor,
        private readonly RememberedDisplay $rememberedDisplay,
        private readonly ViewBar $viewBar,
        private readonly FilterSheet $filterSheet,
    ) {
    }

    #[Route('/invoices/recurring', name: 'app_recurring', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyUnlessStaff();
        // The builder's rows (spec D11) become the list's working filter, in its address.
        if ($request->query->has(FilterRows::MARKER)) {
            return $this->redirectToRoute('app_recurring', $this->builtQuery($request->query), Response::HTTP_SEE_OTHER);
        }
        [$views, $view, $shown] = $this->views($request->query);
        $list = $this->recurringLists->list();
        $filterParam = WorkingFilter::param($shown, $view->filter);
        // A view keeps its own order; the address names the sort only when it differs (spec D10).
        $viewSort = ViewState::sortParam($view->sort, RecurringFilter::NEXT);
        $sort = $request->query->getString('sort', $viewSort);
        $sort = \in_array(ltrim($sort, '-'), RecurringFilter::SORTS, true) ? $sort : RecurringFilter::NEXT;
        // An instance holds one firm (ADR 0039): an old ?firm= filters nothing.
        $severalFirms = \count($this->firmNames()) > 1;

        $page = $this->templates->page(new RecurringFilter(null, null, false, max(1, $request->query->getInt('page', 1)), $shown, $this->counted($views), $sort));
        $lines = $this->overview->prepare($page->templates);
        $today = $this->clock->today();
        $amounts = $this->amounts->payable($page->templates, $lines, $today);

        $query = array_filter(['view' => $view->isAll() ? null : $view->key, 'sort' => $viewSort === $sort ? null : $sort, WorkingFilter::PARAM => $filterParam], static fn (?string $value): bool => null !== $value);
        // The columns and density changed in the Columns sheet, kept in the address next to the view and for the person (spec D10, D-display-3).
        $working = $this->rememberedDisplay->read(RecurringColumns::LIST, ['severalFirms' => $severalFirms], $list->key, $view, $request->query->all());
        $query += $working->query;
        $here = http_build_query(1 === $page->page ? $query : $query + ['page' => (string) $page->page]);
        $bar = $this->viewBar->of($list, $views, $view, $shown, $sort, RecurringFilter::NEXT, $working, 'app_recurring', []);
        // The failure sits under the description, or under the name when the description is hidden.
        $alertColumn = \in_array('description', $working->display->keys(), true) ? 'description' : 'name';

        return $this->render('@Invoicing/recurring/index.html.twig', [
            'firstRun' => 0 === ($page->counts[ListDefinition::ALL] ?? array_sum($this->overview->counts(null))),
            'page' => $page,
            'view' => $view->isAll() ? '' : $view->key,
            'emptyTitle' => $this->emptyTitle($view),
            'working' => $filterParam ?? '',
            ...$this->filterSheet->of($list, $shown, fn (ViewFilter $next): string => $this->generateUrl('app_recurring', [WorkingFilter::PARAM => WorkingFilter::param($next, $view->filter)] + array_diff_key($query, [WorkingFilter::PARAM => true]))),
            'sort' => $sort,
            'sortKept' => $viewSort === $sort ? null : $sort,
            ...$bar,
            ...$this->tabs($views, $view->key, $page, $query, $bar['viewBar']['modified']),
            'columns' => $working->display->table($sort, fn (string $next): string => $this->generateUrl('app_recurring', ['sort' => $next, 'page' => null] + $query)),
            'rows' => array_map(fn (RecurringInvoice $template): array => $this->row($template, $page->isFailing($template), $amounts[$template->id()] ?? null, $working, $alertColumn, $here, $today), $page->templates),
            'density' => $working->tableDensity(),
            'picker' => $this->picker($working, $query),
            'workingQuery' => $working->query,
            'baseUrl' => $this->generateUrl('app_recurring', $query),
            'filtered' => null !== $filterParam,
        ]);
    }

    /**
     * A template, read only: what a row of the list opens in the sheet (docs/design/overlays.md),
     * and the same URL as a page. Edit loads the form in place; the other actions are the row's,
     * dialogs over this detail.
     */
    #[Route('/invoices/recurring/{id}', name: 'app_recurring_show', requirements: ['id' => Requirement::UUID_V7], methods: ['GET'])]
    public function show(string $id): Response
    {
        $this->denyUnlessStaff();
        $template = $this->template($id);
        $lines = $this->templates->lines($template);
        $today = $this->clock->today();
        $next = $template->nextOccurrence();
        $state = $template->state();
        $priced = $this->amounts->breakdown($template, $lines, $today);
        $totals = $priced['totals'] ?? null;
        $code = RecurringState::Ended !== $state ? $template->lastFailureCode() : null;
        $failure = null === $code ? null : $this->failureText($code);
        $description = $this->description($template, $next ?? $today);

        $facts = [
            ['label' => $this->translator->trans('recurring.list.column.customer'), 'value' => $template->customer()->displayName()],
            ['label' => $this->translator->trans('invoice.total.payable'), 'value' => $totals instanceof DocumentTotals ? $this->amountText->shown($totals->payable) : null, 'big' => true],
            ['label' => $this->translator->trans('recurring.list.column.interval'), 'value' => $this->schedule($template)],
            ['label' => $this->translator->trans('recurring.list.column.next'), 'value' => RecurringState::Active === $state && $next instanceof \DateTimeImmutable ? $this->date($next) : null, 'empty' => $this->translator->trans('recurring.list.no_next')],
            ['label' => $this->translator->trans('recurring.list.column.delivery'), 'value' => $this->translator->trans('recurring.list.delivery.'.$template->delivery()->value)],
        ];
        if (\count($this->firmNames()) > 1) {
            $facts[] = ['label' => $this->translator->trans('recurring.list.column.firm'), 'value' => $template->firm()->name()];
        }

        return $this->render('@Invoicing/recurring/show.html.twig', [
            'template' => $template,
            'title' => '' !== $template->name() ? $template->name() : $description,
            'badge' => ['label' => $this->translator->trans('recurring.list.tab.'.$state->value), 'tone' => match ($state) {
                RecurringState::Active => 'green',
                RecurringState::Paused => 'amber',
                RecurringState::Ended => 'gray',
            }],
            'facts' => $facts,
            'description' => $description,
            'failure' => null === $code || null === $failure ? null : ['text' => $this->translator->trans('recurring.list.failed', ['reason' => $failure])] + $this->fix($template, $code),
            'lines' => array_map(fn (PricedLine $line): array => [
                'text' => $line->input->text,
                'quantity' => trim($this->amountText->quantity($line->values->quantity->toDecimal()).' '.$line->input->unit),
                'price' => $this->amountText->shown($line->input->price),
                'rate' => $line->values->rate instanceof Percent ? $this->amountText->trimmed($line->values->rate->decimal()).' %' : '',
                'total' => $this->amountText->shown($line->amounts->gross),
            ], $priced['lines'] ?? []),
            'totals' => $totals instanceof DocumentTotals ? [
                'net' => $this->amountText->shown($totals->net),
                'vat' => $totals->vat->isZero() ? null : $this->amountText->shown($totals->vat),
                'rounding' => $totals->rounding->isZero() ? null : $this->amountText->shown($totals->rounding),
                'payable' => $this->amountText->shown($totals->payable),
            ] : null,
        ]);
    }

    #[Route('/invoices/recurring/{id}/pause', name: 'app_recurring_pause', requirements: ['id' => Requirement::UUID_V7], methods: ['GET', 'POST'])]
    public function pause(string $id, Request $request): Response
    {
        return $this->act($id, $request, 'pause', function (RecurringInvoice $template): void {
            // Read before the change: it starts the session over.
            $customer = $template->customer()->displayName();
            $this->lifecycle->pause($template->id());
            $this->addFlash('success', $this->translator->trans('recurring.flash.paused', ['customer' => $customer]));
        });
    }

    #[Route('/invoices/recurring/{id}/resume', name: 'app_recurring_resume', requirements: ['id' => Requirement::UUID_V7], methods: ['GET', 'POST'])]
    public function resume(string $id, Request $request): Response
    {
        return $this->act($id, $request, 'resume', function (RecurringInvoice $template): void {
            $skipped = $this->lifecycle->resume($template->id(), $this->clock->today());
            $resumed = $this->templates->current($template->id());
            $next = $resumed->nextOccurrence();
            $message = $next instanceof \DateTimeImmutable
                ? $this->translator->trans('recurring.flash.resumed', ['date' => $this->date($next)])
                : $this->translator->trans('recurring.flash.resumed_ended');
            if ([] !== $skipped) {
                $message .= ' '.$this->translator->trans('recurring.flash.skipped', ['count' => \count($skipped), 'dates' => $this->dates($skipped)]);
            }
            $this->addFlash('success', $message);
            // A date due today after the day's run is issued now rather than tomorrow.
            $this->afterTodaysRun->afterChange($template->id());
        });
    }

    #[Route('/invoices/recurring/{id}/end', name: 'app_recurring_end', requirements: ['id' => Requirement::UUID_V7], methods: ['GET', 'POST'])]
    public function end(string $id, Request $request): Response
    {
        return $this->act($id, $request, 'end', function (RecurringInvoice $template): void {
            $customer = $template->customer()->displayName();
            $this->lifecycle->end($template->id());
            $this->addFlash('success', $this->translator->trans('recurring.flash.ended', ['customer' => $customer]));
        });
    }

    #[Route('/invoices/recurring/{id}/delete', name: 'app_recurring_delete', requirements: ['id' => Requirement::UUID_V7], methods: ['GET', 'POST'])]
    public function delete(string $id, Request $request): Response
    {
        return $this->act($id, $request, 'delete', function (RecurringInvoice $template): void {
            $customer = $template->customer()->displayName();
            $this->lifecycle->delete($template->id());
            $this->addFlash('success', $this->translator->trans('recurring.flash.deleted', ['customer' => $customer]));
        });
    }

    /**
     * Issues the template's next date today, through the same path as the daily run,
     * so it is issued once however often the form is sent: the form names the date it
     * was shown for, and a date issued meanwhile issues nothing.
     */
    #[Route('/invoices/recurring/{id}/issue_now', name: 'app_recurring_issue_now', requirements: ['id' => Requirement::UUID_V7], methods: ['GET', 'POST'])]
    public function issueNow(string $id, Request $request): Response
    {
        return $this->act($id, $request, 'issue_now', function (RecurringInvoice $template) use ($request): void {
            $occurrence = \DateTimeImmutable::createFromFormat('!Y-m-d', $request->request->getString('occurrence'));
            if (!$occurrence instanceof \DateTimeImmutable) {
                $this->addFlash('warning', $this->translator->trans('recurring.flash.already_issued'));

                return;
            }
            try {
                $invoice = $this->issue->issue($template->id(), $occurrence, $this->clock->today());
            } catch (IssueRefused $refused) {
                $this->addFlash('error', $this->translator->trans('recurring.flash.issue_refused', ['problems' => implode(' ', array_map(fn (IssueProblem $problem): string => $this->translator->trans($problem->key), $refused->problems))]));

                return;
            } catch (RecurringChangedMeanwhile) {
                $this->addFlash('warning', $this->translator->trans('recurring.flash.changed_meanwhile'));

                return;
            }
            if (!$invoice instanceof Invoice) {
                // Nothing issued: paused or ended meanwhile, or the date was taken by the run or another click.
                $active = RecurringState::Active === $this->templates->current($template->id())->state();
                $this->addFlash('warning', $this->translator->trans($active ? 'recurring.flash.already_issued' : 'recurring.flash.not_active'));

                return;
            }
            // Read before delivering, which may start the session over.
            $number = $invoice->number();
            // Never late: a person chose to issue it now, and sees what happened at once.
            $outcome = $this->delivery->deliver($invoice, $this->templates->get($template->id()), false);
            if ($outcome->isRefused()) {
                // The person sees why at once, and the invoice waits under To send; the template
                // keeps what it had, so a failure of the run stays until a delivery works.
                $this->addFlash('warning', $this->translator->trans('recurring.flash.issued_refused', ['number' => $number, 'reason' => $this->failureText((string) $outcome->code)]));

                return;
            }
            // Issued and delivered by hand, so whatever failed on the last run is behind it.
            $this->work->run(function () use ($template): void {
                $this->templates->lock($template->id())->clearFailure();
            });
            $this->addFlash('success', $this->translator->trans(RecurringOutcome::SENT === $outcome->kind ? 'recurring.flash.issued_sent' : 'recurring.flash.issued_review', ['number' => $number]));
        });
    }

    /**
     * Where a failure is fixed: the firm's mail settings for a missing server, which
     * only an owner may open; the contact for an address it lacks; the invoices to
     * send for a send that needs a hand; else the template itself.
     *
     * @return array{href?: string, action?: string}
     */
    private function fix(RecurringInvoice $template, string $code): array
    {
        [$href, $action] = match (true) {
            'send.no_server' === $code => $this->owner->isOwner() ? [$this->generateUrl('app_firm_mail'), 'mail'] : [null, null],
            \in_array($code, ['send.no_recipient', 'send.bad_address', 'invoice.problem.customer_address_incomplete', 'invoice.problem.customer_archived'], true) => [$this->generateUrl('app_contact_show', ['id' => $template->customer()->id()]), 'contact'],
            str_starts_with($code, 'send.') => [$this->generateUrl('app_invoices', ['toSend' => 1]), 'to_send'],
            default => [$this->generateUrl('app_recurring_edit', ['id' => $template->id()]), 'edit'],
        };

        return null === $href ? [] : ['href' => $href, 'action' => $this->translator->trans('recurring.list.fix.'.$action)];
    }

    /**
     * What a failure code says: a problem key of an issue, or "send." and the reason a
     * send was refused; a code without a text of its own gets the general one.
     */
    private function failureText(string $code): string
    {
        $key = str_starts_with($code, 'send.') ? 'recurring.failure.'.$code : $code;
        $text = $this->translator->trans($key);

        return $text === $key ? $this->translator->trans('recurring.failure.other') : $text;
    }

    /**
     * The dialog of an action, or the action itself on POST, which goes back to the
     * list it came from.
     *
     * @param callable(RecurringInvoice): void $perform
     */
    private function act(string $id, Request $request, string $action, callable $perform): Response
    {
        $this->denyUnlessStaff();
        $template = $this->template($id);
        $return = $request->isMethod('POST') ? $request->request->getString('return') : $request->query->getString('return');
        if (!$request->isMethod('POST')) {
            return $this->dialog($template, $action, $return);
        }
        if (!$this->isCsrfTokenValid(self::ACTION_TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
        try {
            $perform($template);
        } catch (RecurringRefused $refused) {
            $this->addFlash('error', implode(' ', array_map(fn (IssueProblem $problem): string => $this->translator->trans($problem->key), $refused->problems)));
        } catch (UnknownRecurringInvoice) {
            throw new NotFoundHttpException();
        }

        return $this->toList($return);
    }

    private function dialog(RecurringInvoice $template, string $action, string $return): Response
    {
        $today = $this->clock->today();
        $next = $template->nextOccurrence();
        $parameters = [
            'template' => $template,
            'action' => $action,
            'route' => 'app_recurring_'.$action,
            'return' => $return,
            'cancel' => $this->listUrl($return),
            'customer' => $template->customer()->displayName(),
            'description' => $this->description($template, $next ?? $today),
            'interval' => $this->interval($template),
            'next' => $next instanceof \DateTimeImmutable ? $this->date($next) : null,
            'danger' => \in_array($action, ['end', 'delete'], true),
        ];
        if ('resume' === $action) {
            // A copy outside the session works the resume out, so the dialog can name
            // what it would skip; nothing of it is stored.
            $preview = clone $template;
            $skipped = RecurringState::Paused === $template->state() ? $preview->resume($today) : [];
            $parameters['skipped'] = array_map($this->date(...), $skipped);
            $parameters['resumesOn'] = $preview->nextOccurrence() instanceof \DateTimeImmutable ? $this->date($preview->nextOccurrence()) : null;
        }
        if ('issue_now' === $action) {
            $parameters['occurrence'] = $next?->format('Y-m-d');
            $parameters['occurrenceShown'] = $next instanceof \DateTimeImmutable ? $this->date($next) : null;
            $parameters['issueDate'] = $this->date($today);
            $parameters['taxPoint'] = $this->date($template->taxPoint()->for($today));
            $parameters['dueDate'] = $this->date($today->modify(\sprintf('+%d days', $template->dueDays())));
            $parameters['available'] = RecurringState::Active === $template->state() && $next instanceof \DateTimeImmutable;
        }
        if ('delete' === $action) {
            $parameters['available'] = !$template->isIssued();
        }

        return $this->render('@Invoicing/recurring/_'.$action.'.html.twig', $parameters);
    }

    /**
     * The list's views as its address names them (spec D10): the person's views, the one
     * ?view= names (or ?tab=, the address of the tabs the list had), its All when none,
     * and the filter the list shows, the view's own unless f= changed it.
     *
     * @param InputBag<string> $input
     *
     * @return array{list<ListView>, ListView, ViewFilter}
     */
    private function views(InputBag $input): array
    {
        $views = $this->listViews->of($this->recurringLists->list(), $this->actor->userId());
        $view = ListViews::chosen($views, $input->getString('view', $input->getString('tab')));

        return [$views, $view, WorkingFilter::decode($input->getString(WorkingFilter::PARAM), $this->recurringLists->list()) ?? $view->filter];
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
     * into the working filter, which is left out when it is the view's own.
     *
     * @param InputBag<string> $input
     *
     * @return array<string, mixed>
     */
    private function builtQuery(InputBag $input): array
    {
        [, $view] = $this->views($input);
        $query = array_diff_key($input->all(), [FilterRows::PARAM => true, FilterRows::MARKER => true, WorkingFilter::PARAM => true, 'page' => true]);

        return array_filter($query + [WorkingFilter::PARAM => WorkingFilter::param(FilterRows::parse($input->all(FilterRows::PARAM), $this->recurringLists->list()), $view->filter)], static fn (mixed $value): bool => null !== $value && '' !== $value);
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
    private function tabs(array $views, string $active, RecurringPage $page, array $query, bool $modified): array
    {
        [$primary, $overflow] = ListViews::placed($views);
        $link = fn (ListView $view, bool $count): array => [
            'label' => $view->translated ? $this->translator->trans($view->name) : $view->name,
            // A view opens with its own filter and columns: the changed ones belong to the view they were changed on (spec D10).
            'href' => $this->generateUrl('app_recurring', ['view' => $view->isAll() ? null : $view->key] + array_diff_key($query, ['view' => true, 'sort' => true, 'page' => true, WorkingFilter::PARAM => true] + array_flip(WorkingDisplay::KEYS))),
            'count' => $count && $view->showCount ? ($page->counts[$view->key] ?? null) : null,
            'active' => $view->key === $active,
            'modified' => $modified && $view->key === $active,
            'private' => $view->private,
        ];

        return ['tabs' => array_map(static fn (ListView $view): array => $link($view, true), $primary), 'moreViews' => array_map(static fn (ListView $view): array => $link($view, false), $overflow)];
    }

    /**
     * The Columns sheet: what it keeps of the list, and the list without the changed columns.
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
            'resetHref' => $this->generateUrl('app_recurring', $kept + [WorkingDisplay::DENSITY => '']),
            'modified' => $working->modified,
        ];
    }

    /**
     * What an empty view says: a shipped view its own words, any other view that nothing matches.
     */
    private function emptyTitle(ListView $view): string
    {
        return $this->translator->trans(\in_array($view->key, self::SHIPPED, true) ? 'recurring.list.empty.'.$view->key : 'recurring.list.empty.view');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(RecurringInvoice $template, bool $failing, ?Money $amount, WorkingState $working, string $alertColumn, string $return, \DateTimeImmutable $today): array
    {
        $state = $template->state();
        $next = $template->nextOccurrence();
        $customer = $template->customer()->displayName();
        $code = $failing ? (string) $template->lastFailureCode() : null;
        $failure = null === $code ? null : $this->failureText($code);
        $description = $this->description($template, $next ?? $today);
        $nextText = RecurringState::Active === $state && $next instanceof \DateTimeImmutable ? $this->date($next) : '';
        $cells = $working->display->cells(new RecurringRow(
            $template->name(),
            $customer,
            $this->generateUrl('app_contact_show', ['id' => $template->customer()->id()]),
            $description,
            $template->firm()->name(),
            $this->schedule($template),
            $nextText,
            $this->translator->trans('recurring.list.delivery.'.$template->delivery()->value),
            $amount instanceof Money ? $this->amountText->shown($amount) : '',
        ));

        return [
            'id' => $template->id(),
            'href' => $this->generateUrl('app_recurring_show', ['id' => $template->id()]),
            'opens' => 'sheet',
            'label' => $template->name(),
            'secondary' => implode(' · ', array_filter([$customer, $description, $this->schedule($template), $nextText], static fn (string $part): bool => '' !== $part)),
            // The description stays; the failure is a line of its own, with the way to the place that fixes it.
            'alert' => null === $code || null === $failure ? null : ['text' => $this->translator->trans('recurring.list.failed', ['reason' => $failure]), 'column' => $alertColumn] + $this->fix($template, $code),
            'cells' => $cells,
            'actions' => $this->actions($template, $return),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function actions(RecurringInvoice $template, string $return): array
    {
        $dialog = fn (string $action): array => [
            'label' => $this->translator->trans('recurring.list.action.'.$action),
            'href' => $this->generateUrl('app_recurring_'.$action, ['id' => $template->id(), 'return' => '' === $return ? null : $return]),
            'method' => 'GET',
            'dialog' => true,
        ];
        $actions = [['label' => $this->translator->trans('recurring.list.action.edit'), 'href' => $this->generateUrl('app_recurring_edit', ['id' => $template->id()]), 'method' => 'GET', 'icon' => 'pencil', 'plain' => true]];
        $state = $template->state();
        if (RecurringState::Active === $state) {
            $actions[] = $dialog('issue_now');
            $actions[] = $dialog('pause');
        }
        if (RecurringState::Paused === $state) {
            $actions[] = $dialog('resume');
        }
        if (RecurringState::Ended !== $state) {
            $actions[] = $dialog('end');
        }
        if (!$template->isIssued()) {
            $actions[] = $dialog('delete');
        }

        return $actions;
    }

    private function interval(RecurringInvoice $template): string
    {
        $recurrence = $template->recurrence();
        $text = $this->translator->trans('recurring.list.interval.'.$recurrence->unit()->value, ['every' => $recurrence->every()]);

        return $recurrence->onLastDayOfMonth() ? $text.$this->translator->trans('recurring.list.interval.last_day') : $text;
    }

    /**
     * How often, and until when: one column, so the list fits a laptop.
     */
    private function schedule(RecurringInvoice $template): string
    {
        return $this->interval($template).' · '.$this->endText($template);
    }

    private function endText(RecurringInvoice $template): string
    {
        if (RecurringState::Ended === $template->state()) {
            $ended = $template->endedAt();

            return trim($this->translator->trans('recurring.list.end.ended', ['date' => $ended instanceof \DateTimeImmutable ? $this->date($ended) : '']));
        }
        $recurrence = $template->recurrence();

        return match ($recurrence->end()) {
            RecurrenceEnd::Never => $this->translator->trans('recurring.list.end.never'),
            RecurrenceEnd::OnDate => $this->translator->trans('recurring.list.end.on_date', ['date' => $this->date($recurrence->endOn() ?? throw new \LogicException('An end on a date has its date.'))]),
            RecurrenceEnd::AfterCount => $this->translator->trans('recurring.list.end.after_count', ['left' => max(0, ($recurrence->endAfter() ?? 0) - $template->issuedCount())]),
        };
    }

    private function description(RecurringInvoice $template, \DateTimeImmutable $occurrence): string
    {
        $text = PeriodText::resolve($template->description(), $occurrence);

        // {document_number} is known only once the invoice has its number.
        return str_replace('{document_number}', $this->translator->trans('recurring.preview.document_number'), $text);
    }

    /**
     * @return array<string, string> names by firm id
     */
    private function firmNames(): array
    {
        $names = [];
        foreach ($this->firms->all() as $firm) {
            $names[$firm->id()] = $firm->name();
        }

        return $names;
    }

    private function date(\DateTimeImmutable $date): string
    {
        return $date->format($this->translator->trans('format.date'));
    }

    /**
     * @param list<\DateTimeImmutable> $dates
     */
    private function dates(array $dates): string
    {
        return implode(', ', array_map($this->date(...), $dates));
    }

    private function template(string $id): RecurringInvoice
    {
        try {
            return $this->templates->get($id);
        } catch (UnknownRecurringInvoice) {
            throw new NotFoundHttpException();
        }
    }

    private function toList(string $return): RedirectResponse
    {
        return $this->redirect($this->listUrl($return));
    }

    /**
     * Only the known parts of the query come back: the value travelled through the browser.
     */
    private function listUrl(string $return): string
    {
        parse_str($return, $query);

        return $this->generateUrl('app_recurring', array_filter(array_intersect_key($query, array_flip(self::LIST_QUERY)), \is_string(...)));
    }

    private function denyUnlessStaff(): void
    {
        if (!$this->staff->isStaff()) {
            throw new AccessDeniedHttpException('Only staff work with recurring invoices.');
        }
    }
}
