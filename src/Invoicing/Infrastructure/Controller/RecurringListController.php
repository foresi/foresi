<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Controller;

use App\Identity\Application\StaffCheck;
use App\Invoicing\Application\IssueProblem;
use App\Invoicing\Application\IssueRecurringOccurrence;
use App\Invoicing\Application\IssueRefused;
use App\Invoicing\Application\RecurringAmounts;
use App\Invoicing\Application\RecurringChangedMeanwhile;
use App\Invoicing\Application\RecurringFilter;
use App\Invoicing\Application\RecurringInvoices;
use App\Invoicing\Application\RecurringLifecycle;
use App\Invoicing\Application\RecurringOverview;
use App\Invoicing\Application\RecurringRefused;
use App\Invoicing\Application\UnknownRecurringInvoice;
use App\Invoicing\Domain\PeriodText;
use App\Invoicing\Domain\RecurrenceEnd;
use App\Invoicing\Domain\RecurringInvoice;
use App\Invoicing\Domain\RecurringState;
use App\Invoicing\Infrastructure\Form\AmountText;
use App\Party\Application\LegalEntities;
use App\Party\Application\TransactionalWork;
use App\Shared\Domain\Money;
use App\Tenancy\Application\TenantClock;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
    private const array LIST_QUERY = ['tab', 'firm', 'page'];
    private const array STATE_TONES = ['active' => 'green', 'paused' => 'amber', 'ended' => 'gray'];

    public function __construct(
        private readonly RecurringInvoices $templates,
        private readonly RecurringLifecycle $lifecycle,
        private readonly RecurringOverview $overview,
        private readonly RecurringAmounts $amounts,
        private readonly IssueRecurringOccurrence $issue,
        private readonly TransactionalWork $work,
        private readonly LegalEntities $firms,
        private readonly AmountText $amountText,
        private readonly TenantClock $clock,
        private readonly StaffCheck $staff,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/invoices/recurring', name: 'app_recurring', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyUnlessStaff();
        $tab = $request->query->getString('tab', RecurringOverview::ACTIVE);
        $tab = \in_array($tab, RecurringOverview::TABS, true) ? $tab : RecurringOverview::ACTIVE;
        $firms = $this->firmNames();
        $firm = $request->query->getString('firm');
        $firm = isset($firms[$firm]) ? $firm : '';
        $firmId = '' === $firm ? null : $firm;

        $counts = $this->overview->counts($firmId);
        $page = $this->templates->page(new RecurringFilter(
            $firmId,
            RecurringOverview::FAILING === $tab ? null : RecurringState::from($tab),
            RecurringOverview::FAILING === $tab,
            max(1, $request->query->getInt('page', 1)),
        ));
        $lines = $this->overview->prepare($page->templates);
        $today = $this->clock->today();
        $amounts = $this->amounts->payable($page->templates, $lines, $today);

        $query = array_filter(['tab' => RecurringOverview::ACTIVE === $tab ? null : $tab, 'firm' => $firmId], static fn (?string $value): bool => null !== $value);
        $here = http_build_query(1 === $page->page ? $query : $query + ['page' => (string) $page->page]);
        $severalFirms = \count($firms) > 1;

        return $this->render('invoicing/recurring/index.html.twig', [
            'firstRun' => 0 === array_sum(null === $firmId ? $counts : $this->overview->counts(null)),
            'page' => $page,
            'tab' => $tab,
            'firm' => $firm,
            'firms' => $severalFirms ? ['' => $this->translator->trans('invoice.list.all_firms')] + $firms : [],
            'tabs' => $this->tabs($counts, $tab, $query),
            'columns' => $this->columns($severalFirms),
            'rows' => array_map(fn (RecurringInvoice $template): array => $this->row($template, $amounts[$template->id()] ?? null, $severalFirms, $here, $today), $page->templates),
            'baseUrl' => $this->generateUrl('app_recurring', $query),
            'filtered' => null !== $firmId,
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
            if (!$invoice instanceof \App\Invoicing\Domain\Invoice) {
                $this->addFlash('warning', $this->translator->trans('recurring.flash.already_issued'));

                return;
            }
            // Issued by hand, so whatever failed on the last run is behind it.
            $this->work->run(function () use ($template): void {
                $this->templates->lock($template->id())->clearFailure();
            });
            $this->addFlash('success', $this->translator->trans('recurring.flash.issued', ['number' => $invoice->number()]));
        });
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

        return $this->render('invoicing/recurring/_'.$action.'.html.twig', $parameters);
    }

    /**
     * @param array<string, int>    $counts
     * @param array<string, string> $query
     *
     * @return list<array<string, mixed>>
     */
    private function tabs(array $counts, string $tab, array $query): array
    {
        $tabs = [];
        foreach (RecurringOverview::TABS as $key) {
            $tabs[] = [
                'label' => $this->translator->trans('recurring.list.tab.'.$key),
                'href' => $this->generateUrl('app_recurring', ['tab' => RecurringOverview::ACTIVE === $key ? null : $key] + $query),
                'count' => $counts[$key] ?? 0,
                'active' => $key === $tab,
            ];
        }

        return $tabs;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function columns(bool $severalFirms): array
    {
        $keys = ['customer' => true, 'description' => false];
        if ($severalFirms) {
            $keys['firm'] = false;
        }
        $keys += ['interval' => false, 'next' => true, 'end' => false, 'delivery' => false, 'amount' => true, 'state' => false];
        $columns = [];
        foreach ($keys as $key => $phone) {
            $columns[] = ['key' => $key, 'label' => $this->translator->trans('recurring.list.column.'.$key), 'align' => 'amount' === $key ? 'right' : 'left', 'phone' => $phone];
        }

        return $columns;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(RecurringInvoice $template, ?Money $amount, bool $severalFirms, string $return, \DateTimeImmutable $today): array
    {
        $state = $template->state();
        $failing = RecurringState::Ended !== $state && null !== $template->lastFailureCode();
        $next = $template->nextOccurrence();
        $customer = $template->customer()->displayName();
        $failure = $failing ? $this->translator->trans('recurring.list.failure', ['code' => $template->lastFailureCode()]) : null;
        $stateText = $this->translator->trans('recurring.list.state.'.($failing ? 'failing' : $state->value));
        $description = $this->description($template, $next ?? $today);
        $nextText = RecurringState::Active === $state && $next instanceof \DateTimeImmutable ? $this->date($next) : '';
        $cells = [
            'customer' => $customer,
            'description' => null === $failure ? $description : ['text' => $failure, 'icon' => 'triangle-alert', 'hint' => $failure],
            'interval' => $this->interval($template),
            'next' => $nextText,
            'end' => $this->endText($template),
            'delivery' => $this->translator->trans('recurring.list.delivery.'.$template->delivery()->value),
            'amount' => $amount instanceof Money ? $this->amountText->shown($amount) : '',
            'state' => ['text' => $stateText, 'tone' => $failing ? 'red' : self::STATE_TONES[$state->value], 'hint' => $failure],
        ];
        if ($severalFirms) {
            $cells['firm'] = $template->firm()->name();
        }

        return [
            'id' => $template->id(),
            'href' => $this->generateUrl('app_recurring_edit', ['id' => $template->id()]),
            'plain' => true,
            'label' => $customer,
            'secondary' => implode(' · ', array_filter([$failure ?? $description, $this->interval($template), $nextText, $stateText], static fn (string $part): bool => '' !== $part)),
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

    private function endText(RecurringInvoice $template): string
    {
        if (RecurringState::Ended === $template->state()) {
            $ended = $template->endedAt();

            return $ended instanceof \DateTimeImmutable ? $this->translator->trans('recurring.list.end.ended', ['date' => $this->date($ended)]) : $this->translator->trans('recurring.list.state.ended');
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
