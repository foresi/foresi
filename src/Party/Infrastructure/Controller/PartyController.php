<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Infrastructure\Controller;

use Foresi\Audit\Application\AuditActor;
use Foresi\Party\Application\ArchiveParty;
use Foresi\Party\Application\ContactImports;
use Foresi\Party\Application\ImportedBy;
use Foresi\Party\Application\PartyDetails;
use Foresi\Party\Application\PartyHistoryEntry;
use Foresi\Party\Application\PartyPage;
use Foresi\Party\Application\PartyQuery;
use Foresi\Party\Application\PartyRow;
use Foresi\Party\Application\Query\ContactDetails;
use Foresi\Party\Application\Query\ContactList;
use Foresi\Party\Application\RefreshRegistryChecks;
use Foresi\Party\Application\TransactionalWork;
use Foresi\Party\Application\View\ContactLists;
use Foresi\Party\Contract\ContactTabs;
use Foresi\Party\Domain\AddressType;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyAddress;
use Foresi\Party\Domain\PartyBankAccount;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\RegistryCheck;
use Foresi\Party\Domain\RegistryResult;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Infrastructure\Column\ContactSource;
use Foresi\Party\Infrastructure\Http\WebsiteLink;
use Foresi\Party\Infrastructure\View\ContactViews;
use Foresi\Shared\Application\Field\FieldProperties;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\FilterRows;
use Foresi\Shared\Application\View\ListView;
use Foresi\Shared\Application\View\ListViews;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Application\View\ViewState;
use Foresi\Shared\Application\View\WorkingFilter;
use Foresi\Shared\Domain\Id;
use Foresi\Shared\Infrastructure\View\FilterSheet;
use Foresi\Ui\Column\ListColumnRegistry;
use Foresi\Ui\Column\RememberedDisplay;
use Foresi\Ui\Contract\Opens;
use Foresi\Ui\Contract\WorkingDisplay;
use Foresi\Ui\Contract\WorkingState;
use Foresi\Ui\Slot\SlotRegistry;
use Foresi\Ui\View\ViewBar;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The address book: list, detail and the actions on existing contacts. A
 * contact that does not exist answers 404.
 */
final class PartyController extends AbstractController
{
    public const string ACTION_TOKEN = 'party_action';

    private const string DEFAULT_SORT = 'name';
    /** The address book's own tabs by their weights; the tabs of other modules come from ContactTabs::SLOT. */
    private const array TABS = ['overview' => 0, 'contacts' => 10, 'addresses' => 20, 'bank-accounts' => 30, 'history' => 40];
    public const int MAX_BULK = 1000;
    public const int MAX_SEARCH = 100;
    private const array QUIET_FIELDS = ['registrationKey', 'registrationCountry', 'billingFlag', 'primaryFlag', 'viesCheckedAt', 'unreliablePayerCheckedAt', 'unreliablePayerAccounts', 'party', 'company', 'person'];

    public function __construct(
        private readonly PartyQuery $parties,
        private readonly PartyDetails $details,
        private readonly ContactList $contacts,
        private readonly ContactDetails $contactDetails,
        private readonly ArchiveParty $archiveParty,
        private readonly RefreshRegistryChecks $refreshChecks,
        private readonly TransactionalWork $transaction,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
        private readonly AuditActor $actor,
        private readonly ContactImports $imports,
        #[Autowire(service: 'limiter.party_refresh_checks')]
        private readonly RateLimiterFactory $refreshLimiter,
        private readonly ContactSource $contactSource,
        private readonly ListColumnRegistry $listColumns,
        private readonly RememberedDisplay $rememberedDisplay,
        private readonly ContactViews $contactViews,
        private readonly ViewBar $viewBar,
        private readonly FilterSheet $filterSheet,
        private readonly ContactLists $contactLists,
        private readonly SlotRegistry $slots,
        private readonly FieldProperties $fieldProperties,
    ) {
    }

    #[Route('/contacts', name: 'app_contacts', methods: ['GET'])]
    public function index(Request $request): Response
    {
        // The builder's rows (spec D11) become the list's working filter, in its address.
        if ($request->query->has(FilterRows::MARKER)) {
            return $this->redirectToRoute('app_contacts', $this->builtQuery($request->query), Response::HTTP_SEE_OTHER);
        }
        [$views, $view, $shown] = $this->contactViews->of($request->query);
        $list = $this->contactLists->list();
        $filterParam = WorkingFilter::param($shown, $view->filter);
        $text = trim(mb_substr($request->query->getString('q'), 0, self::MAX_SEARCH));
        // A view keeps its own order; the address names the sort only when it differs (spec D10).
        $viewSort = ViewState::sortParam($view->sort, self::DEFAULT_SORT);
        // By any attribute the list sorts by, under its key; an older key such as vat still sorts (spec DS2).
        $sort = $this->listColumns->sort(ContactSource::LIST, $request->query->getString('sort', $viewSort), self::DEFAULT_SORT);

        [$importedBy, $importName] = $this->importedBy($request->query);
        $page = $this->contacts->inView($text, $shown, $this->counted($views), $sort, max(1, $request->query->getInt('page', 1)), $importedBy);
        $counts = $this->contacts->counts();
        $import = $importedBy instanceof ImportedBy ? ['import' => $importedBy->importId, 'outcome' => $importedBy->outcome] : [];
        $query = array_filter(['view' => $view->isAll() ? null : $view->key, 'q' => '' === $text ? null : $text, 'sort' => $viewSort === $sort ? null : $sort, WorkingFilter::PARAM => $filterParam], static fn (?string $value): bool => null !== $value) + $import;
        // The quick filters, columns and density changed in the Settings sheet, kept in the address next to the view and for the person (spec D10, D-display-3).
        $working = $this->rememberedDisplay->read(ContactSource::LIST, [], $list->key, $view, $request->query->all(), filters: $list);
        $query += $working->query;
        $bar = $this->viewBar->of($list, $views, $view, $shown, $sort, self::DEFAULT_SORT, $working, 'app_contacts', '' === $text ? [] : ['q' => $text]);
        $archived = [] !== array_filter($shown->on(ContactLists::ARCHIVED), static fn (Condition $condition): bool => true === $condition->value);

        return $this->render('@Party/index.html.twig', [
            'firstRun' => 0 === $counts->all && 0 === $counts->archived,
            'view' => $view->isAll() ? '' : $view->key,
            // The archived contacts are archived already: the list offers no bulk archive.
            'archived' => $archived,
            'working' => $filterParam ?? '',
            ...$this->filterSheet->of($list, $shown, fn (ViewFilter $next): string => $this->generateUrl('app_contacts', [WorkingFilter::PARAM => WorkingFilter::param($next, $view->filter)] + array_diff_key($query, [WorkingFilter::PARAM => true])), pinned: $working->pinned),
            'text' => $text,
            'sort' => $sort,
            'sortKept' => $viewSort === $sort ? null : $sort,
            ...$bar,
            'page' => $page,
            ...$this->tabs($views, $view->key, $page, $query, $bar['viewBar']['modified']),
            'columns' => $working->display->table($sort, fn (string $next): string => $this->generateUrl('app_contacts', ['sort' => $next, 'page' => null] + $query)),
            'rows' => $this->rows($page, $working),
            'density' => $working->tableDensity(),
            'picker' => $this->picker($working, $query),
            'workingQuery' => $working->query,
            'baseUrl' => $this->generateUrl('app_contacts', $query),
            'filtered' => '' !== $text || null !== $filterParam || $importedBy instanceof ImportedBy,
            'importedBy' => $importedBy instanceof ImportedBy ? ['text' => $this->translator->trans('party.imported_by.'.$importedBy->outcome, ['name' => $importName]), 'href' => $this->imports->summaryUrl($importedBy->importId)] : null,
            'listQuery' => ['view' => $view->isAll() ? '' : $view->key, 'q' => $text, 'f' => $filterParam ?? ''] + $import,
        ]);
    }

    #[Route('/contacts/{id}', name: 'app_contact_show', requirements: ['id' => Requirement::UUID_V7], methods: ['GET'])]
    public function show(string $id, Request $request): Response
    {
        $party = $this->contactDetails->party($id);
        $contributed = $this->contributedTabs($party);
        $tab = $request->query->getString('tab', 'overview');
        if (!isset(self::TABS[$tab]) && !isset($contributed[$tab])) {
            $tab = 'overview';
        }

        return $this->detail($party, $id, $tab, $contributed, $request->getLocale());
    }

    /**
     * @param array<string, array{label: string, href: string, weight: int}> $contributed
     */
    private function detail(Party $party, string $id, string $tab, array $contributed, string $locale): Response
    {
        $vies = $party->viesCheck();
        $reliability = $party->unreliablePayerCheck();
        $unreliable = RegistryResult::Unreliable === $reliability?->result;
        $vatId = $party->vatId();
        $badge = $unreliable
            ? ['label' => $this->translator->trans('registry.result.unreliable'), 'tone' => 'red']
            : ($party->isArchived() ? ['label' => $this->translator->trans('party.archived'), 'tone' => 'gray'] : null);

        $data = [
            'party' => $party,
            'id' => $id,
            'badge' => $badge,
            'tab' => $tab,
            'tabs' => $this->detailTabs($party, $tab, $contributed),
            'contributed' => $contributed[$tab]['href'] ?? null,
            'facts' => $this->facts($party),
            'checks' => $this->checks($vies, $reliability),
            'accounts' => $unreliable ? $party->publishedBankAccounts() : [],
            'hasVatId' => $vatId instanceof VatId,
            'note' => $party->privateNote(),
            'defaults' => $this->defaults($party),
            'locale' => $locale,
        ];

        return $this->render('@Party/show.html.twig', $data + match ($tab) {
            'overview' => ['billing' => $this->details->billingAddress($party)?->address()],
            'contacts' => $this->contactsTab($party),
            'addresses' => ['addressRows' => $this->addressRows($party, $this->details->addresses($party))],
            'bank-accounts' => ['accountRows' => $this->accountRows($party, $this->details->bankAccounts($party))],
            'history' => ['timeline' => $this->timeline($party)],
            // A module's tab loads its own page into the contact (ContactTabs).
            default => [],
        });
    }

    #[Route('/contacts/{id}/archive', name: 'app_contact_archive', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function archive(string $id, Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $this->archiveParty->archive([$this->party($id)]);
        $this->addFlash('success', $this->translator->trans('party.flash.archived'));

        return $this->redirectToRoute('app_contact_show', ['id' => $id]);
    }

    #[Route('/contacts/{id}/restore', name: 'app_contact_restore', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function restore(string $id, Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $this->archiveParty->restore([$this->party($id)]);
        $this->addFlash('success', $this->translator->trans('party.flash.restored'));

        return $this->redirectToRoute('app_contact_show', ['id' => $id]);
    }

    /**
     * Archives the selected contacts. Either all of them are existing contacts,
     * or nothing happens and the answer is 404.
     */
    #[Route('/contacts/archive', name: 'app_contacts_archive', methods: ['POST'])]
    public function archiveMany(Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $ids = $this->selectedIds($request);
        if ([] === $ids) {
            return $this->redirectToRoute('app_contacts');
        }
        $found = $this->parties->findMany($ids);
        if (\count($found) !== \count($ids)) {
            throw new NotFoundHttpException();
        }
        $this->archiveParty->archive($found);
        $this->addFlash('success', $this->translator->trans('party.flash.archived_many', ['count' => \count($found)]));

        return $this->redirectToRoute('app_contacts');
    }

    #[Route('/contacts/{id}/registry-checks', name: 'app_contact_refresh_checks', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function refreshChecks(string $id, Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $party = $this->party($id);
        if (!$this->refreshLimiter->create((string) ($this->actor->userId() ?? $request->getClientIp()))->consume()->isAccepted()) {
            $this->addFlash('error', $this->translator->trans('party.flash.checks_rate_limited'));

            return $this->redirectToRoute('app_contact_show', ['id' => $id]);
        }
        $message = $this->translator->trans('party.flash.checks_refreshed');
        $vatId = $party->vatId();
        if ($vatId instanceof VatId) {
            $started = $this->clock->now();
            // Ask the registers first (this can take seconds); the transaction only stores the answers.
            $outcome = $this->refreshChecks->fetch($vatId);
            $this->transaction->run(fn () => $this->refreshChecks->store($party, $outcome));
            if ($outcome->answeredBefore($started)) {
                // The registers keep answers for a day; say so instead of claiming a fresh check.
                $message = $this->translator->trans('party.flash.checks_recent', ['time' => $outcome->oldest()->format($this->translator->trans('format.datetime'))]);
            }
        }
        $this->addFlash('success', $message);

        return $this->redirectToRoute('app_contact_show', ['id' => $id]);
    }

    /**
     * The import the list is narrowed to, when the link of an import summary opened it.
     *
     * @param InputBag<string> $input the query of the list
     *
     * @return array{ImportedBy, string}|array{null, null} the import and the name of its file
     */
    private function importedBy(InputBag $input): array
    {
        $importedBy = ImportedBy::tryFrom($input->getString('import'), $input->getString('outcome', 'create'));
        // An unknown import is not found, so its id narrows nothing.
        $name = $importedBy instanceof ImportedBy ? $this->imports->fileName($importedBy->importId) : null;

        return null !== $name ? [$importedBy, $name] : [null, null];
    }

    private function party(string $id): Party
    {
        return Id::isValid($id) ? ($this->parties->find($id) ?? throw new NotFoundHttpException()) : throw new NotFoundHttpException();
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::ACTION_TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }

    /**
     * @return list<string>
     */
    private function selectedIds(Request $request): array
    {
        $ids = [];
        foreach ($request->request->all('ids') as $id) {
            if (\is_string($id) && Id::isValid($id)) {
                $ids[$id] = $id;
            }
        }

        return \array_slice(array_values($ids), 0, self::MAX_BULK);
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
        [, $view] = $this->contactViews->of($input);
        $query = array_diff_key($input->all(), [FilterRows::PARAM => true, FilterRows::MARKER => true, WorkingFilter::PARAM => true, 'page' => true]);

        return array_filter($query + [WorkingFilter::PARAM => WorkingFilter::param(FilterRows::parse($input->all(FilterRows::PARAM), $this->contactLists->list()), $view->filter)], static fn (mixed $value): bool => null !== $value && '' !== $value);
    }

    /**
     * The tabs of the list and the other views of its Settings sheet (spec D7): All and the primary views
     * as tabs, with the count of those that show one; the other views in the menu.
     *
     * @param list<ListView>        $views
     * @param array<string, string> $query
     *
     * @return array{tabs: list<array<string, mixed>>, moreViews: list<array<string, mixed>>}
     */
    private function tabs(array $views, string $active, PartyPage $page, array $query, bool $modified): array
    {
        [$primary, $overflow] = ListViews::placed($views);
        $link = fn (ListView $view, bool $count): array => [
            'label' => $view->translated ? $this->translator->trans($view->name) : $view->name,
            // A view opens with its own filter and columns: the changed ones belong to the view they were changed on (spec D10).
            'href' => $this->generateUrl('app_contacts', ['view' => $view->isAll() ? null : $view->key] + array_diff_key($query, ['view' => true, 'page' => true, WorkingFilter::PARAM => true] + array_flip(WorkingDisplay::KEYS))),
            'count' => $count && $view->showCount ? ($page->counts[$view->key] ?? null) : null,
            'active' => $view->key === $active,
            'modified' => $modified && $view->key === $active,
            'private' => $view->private,
        ];

        return ['tabs' => array_map(static fn (ListView $view): array => $link($view, true), $primary), 'moreViews' => array_map(static fn (ListView $view): array => $link($view, false), $overflow)];
    }

    /**
     * The Settings sheet: what it keeps of the list, and the list without the changed columns.
     *
     * @param array<string, string|null> $query
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
            'resetHref' => $this->generateUrl('app_contacts', $kept + [WorkingDisplay::DENSITY => '']),
            'modified' => $working->modified,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(PartyPage $page, WorkingState $working): array
    {
        $display = $working->display;

        return array_map(function (PartyRow $row) use ($display): array {
            $cells = $display->cells($row);

            return [
                'id' => $row->id,
                'label' => $row->displayName,
                'href' => $this->generateUrl('app_contact_show', ['id' => $row->id]),
                // The contact opens in the side sheet over the list (docs/design/overlays.md).
                'opens' => Opens::SHEET,
                'secondary' => implode(' · ', array_filter([$this->contactSource->roles($row), $row->registrationNumber, $row->email], static fn (?string $part): bool => null !== $part && '' !== $part)),
                'cells' => $cells,
            ];
        }, $page->rows);
    }

    /**
     * @param array<string, array{label: string, href: string, weight: int}> $contributed
     *
     * @return list<array{label: string, href: string, active: bool}>
     */
    private function detailTabs(Party $party, string $active, array $contributed): array
    {
        $tabs = [];
        foreach (self::TABS as $tab => $weight) {
            $tabs[] = ['label' => $this->translator->trans('party.tab.'.$tab), 'tab' => $tab, 'weight' => $weight];
        }
        foreach ($contributed as $tab => $item) {
            $tabs[] = ['label' => $item['label'], 'tab' => $tab, 'weight' => $item['weight']];
        }
        // Stable: tabs of the same weight keep the address book's order, then the slot's.
        usort($tabs, static fn (array $a, array $b): int => $a['weight'] <=> $b['weight']);

        return array_map(fn (array $tab): array => [
            'label' => $tab['label'],
            'href' => $this->generateUrl('app_contact_show', ['id' => $party->id(), 'tab' => 'overview' === $tab['tab'] ? null : $tab['tab']]),
            'active' => $tab['tab'] === $active,
        ], $tabs);
    }

    /**
     * The tabs other modules add to the contact (ContactTabs), by key; one that names no key, label
     * or page, or a key of the address book's own tabs, is left out.
     *
     * @return array<string, array{label: string, href: string, weight: int}>
     */
    private function contributedTabs(Party $party): array
    {
        $tabs = [];
        foreach ($this->slots->items(ContactTabs::SLOT, ['contactId' => $party->id()]) as $item) {
            $key = $item->data['key'] ?? null;
            $label = $item->data['label'] ?? null;
            $href = $item->data['href'] ?? null;
            if (!\is_string($key) || '' === $key || isset(self::TABS[$key]) || isset($tabs[$key]) || !\is_string($label) || !\is_string($href) || '' === $href) {
                continue;
            }
            $tabs[$key] = ['label' => $label, 'href' => $href, 'weight' => $item->weight];
        }

        return $tabs;
    }

    /**
     * @return list<array{label: string, value: ?string}>
     */
    private function facts(Party $party): array
    {
        $facts = [
            ['label' => $this->translator->trans('party.field.registrationNumber'), 'value' => $party->registrationNumber()?->value],
            ['label' => $this->translator->trans('party.field.vatId'), 'value' => $party->vatId()?->toString()],
            ['label' => $this->translator->trans('party.field.email'), 'value' => $this->details->generalEmail($party)?->address()],
            ['label' => $this->translator->trans('party.field.phone'), 'value' => $party->phone()],
        ];
        // Shown only when known, so a contact without them keeps the four facts every contact has.
        if ('' !== $party->mobile()) {
            $facts[] = ['label' => $this->translator->trans('party.field.mobile'), 'value' => $party->mobile()];
        }
        if ('' !== $party->website()) {
            $facts[] = ['label' => $this->translator->trans('party.field.website'), 'value' => $party->website(), 'href' => WebsiteLink::of($party->website())];
        }

        return $facts;
    }

    /**
     * @return list<array{label: string, text: string, tone: string, checkedAt: \DateTimeImmutable}>
     */
    private function checks(?RegistryCheck ...$checks): array
    {
        $views = [];
        foreach ($checks as $check) {
            if (!$check instanceof RegistryCheck) {
                continue;
            }
            $views[] = [
                'label' => $this->translator->trans('party.check.'.$check->source->value),
                'text' => $this->translator->trans('registry.result.'.$check->result->value),
                'tone' => match ($check->result) {
                    RegistryResult::Valid, RegistryResult::Reliable => 'green',
                    RegistryResult::Invalid, RegistryResult::Unreliable => 'red',
                    RegistryResult::Unavailable => 'amber',
                    RegistryResult::NotRegistered => 'gray',
                },
                'checkedAt' => $check->checkedAt,
            ];
        }

        return $views;
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private function defaults(Party $party): array
    {
        $defaults = $party->invoicingDefaults();

        return [
            ['label' => $this->translator->trans('party.defaults.due_days'), 'value' => $this->translator->trans('party.defaults.days', ['count' => $defaults->dueDays])],
            ['label' => $this->translator->trans('party.defaults.currency'), 'value' => $defaults->currency->value],
            ['label' => $this->translator->trans('party.defaults.language'), 'value' => $this->translator->trans('locale.'.$defaults->language->value)],
            ['label' => $this->translator->trans('party.defaults.reverse_charge'), 'value' => $this->translator->trans($defaults->reverseCharge ? 'party.yes' : 'party.no')],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function contactsTab(Party $party): array
    {
        $rows = [];
        $linked = PartyKind::Person === $party->kind();
        foreach ($this->details->contactLinks($party) as $link) {
            $other = $linked ? $link->company() : $link->person();
            $ids = ['id' => $party->id(), 'part' => $link->id()];
            $actions = [];
            // The company changes its contact persons; a person only shows where it is one.
            if (!$linked) {
                if (!$link->isPrimary()) {
                    $actions[] = ['label' => $this->translator->trans('party.contacts.action.primary'), 'href' => $this->generateUrl('app_contact_person_primary', $ids), 'method' => 'POST'];
                }
                $actions[] = ['label' => $this->translator->trans('party.contacts.action.unlink'), 'href' => $this->generateUrl('app_contact_person_unlink', $ids), 'method' => 'POST', 'confirm' => [
                    'title' => $this->translator->trans('party.contacts.confirm.unlink', ['name' => $other->displayName()]),
                    'text' => $this->translator->trans('party.contacts.confirm.unlink_text'),
                    'accept' => $this->translator->trans('party.contacts.confirm.unlink_accept'),
                    'cancel' => $this->translator->trans('party.cancel'),
                    'tone' => 'danger',
                    'overlay' => true,
                ]];
            }
            $rows[] = [
                'id' => $link->id(),
                'label' => $other->displayName(),
                'href' => $this->generateUrl('app_contact_show', ['id' => $other->id()]),
                'cells' => [
                    'name' => $other->displayName(),
                    'position' => $link->position(),
                    'primary' => $link->isPrimary() ? ['label' => $this->translator->trans('party.contact.primary'), 'tone' => 'green'] : null,
                ],
                'actions' => $actions,
            ];
        }

        return ['contactRows' => $rows, 'isPerson' => $linked];
    }

    /**
     * @param list<PartyAddress> $addresses
     *
     * @return list<array<string, mixed>>
     */
    private function addressRows(Party $party, array $addresses): array
    {
        return array_map(function (PartyAddress $address) use ($party): array {
            $ids = ['id' => $party->id(), 'part' => $address->id()];
            $type = $this->translator->trans('party.address_type.'.$address->type()->value);
            $place = $address->address();
            $actions = [['label' => $this->translator->trans('party.edit'), 'href' => $this->generateUrl('app_contact_address_edit', $ids), 'method' => 'GET', 'dialog' => true]];
            // The billing address is changed, never deleted.
            if (AddressType::Billing !== $address->type()) {
                $actions[] = ['label' => $this->translator->trans('party.addresses.action.delete'), 'href' => $this->generateUrl('app_contact_address_delete', $ids), 'method' => 'POST', 'confirm' => [
                    'title' => $this->translator->trans('party.addresses.confirm.delete'),
                    'text' => $this->translator->trans('party.addresses.confirm.delete_text'),
                    'accept' => $this->translator->trans('party.addresses.confirm.delete_accept'),
                    'cancel' => $this->translator->trans('party.cancel'),
                    'tone' => 'danger',
                    'overlay' => true,
                ]];
            }

            return [
                'id' => $address->id(),
                'label' => $type,
                // An address is a small record: it opens in a modal over the contact (docs/design/overlays.md).
                'href' => $this->generateUrl('app_contact_address_edit', $ids),
                'opens' => Opens::DIALOG,
                'cells' => [
                    'type' => $type,
                    'label' => $address->label(),
                    'address' => implode(', ', array_filter([$place->street, trim($place->postalCode.' '.$place->city), $place->countryCode], static fn (string $part): bool => '' !== $part)),
                ],
                'actions' => $actions,
            ];
        }, $addresses);
    }

    /**
     * @param list<PartyBankAccount> $accounts
     *
     * @return list<array<string, mixed>>
     */
    private function accountRows(Party $party, array $accounts): array
    {
        return array_map(function (PartyBankAccount $account) use ($party): array {
            $ids = ['id' => $party->id(), 'part' => $account->id()];
            $iban = trim(chunk_split($account->iban()->toString(), 4, ' '));
            $actions = [['label' => $this->translator->trans('party.edit'), 'href' => $this->generateUrl('app_contact_account_edit', $ids), 'method' => 'GET', 'dialog' => true]];
            if (!$account->isPrimary()) {
                $actions[] = ['label' => $this->translator->trans('party.accounts.action.primary'), 'href' => $this->generateUrl('app_contact_account_primary', $ids), 'method' => 'POST'];
            }
            $actions[] = ['label' => $this->translator->trans('party.accounts.action.delete'), 'href' => $this->generateUrl('app_contact_account_delete', $ids), 'method' => 'POST', 'confirm' => [
                'title' => $this->translator->trans('party.accounts.confirm.delete', ['iban' => $iban]),
                'text' => $this->translator->trans('party.accounts.confirm.delete_text'),
                'accept' => $this->translator->trans('party.accounts.confirm.delete_accept'),
                'cancel' => $this->translator->trans('party.cancel'),
                'tone' => 'danger',
                'overlay' => true,
            ]];

            return [
                'id' => $account->id(),
                'label' => $iban,
                // A bank account is a small record: it opens in a modal over the contact (docs/design/overlays.md).
                'href' => $this->generateUrl('app_contact_account_edit', $ids),
                'opens' => Opens::DIALOG,
                'cells' => [
                    'iban' => $iban,
                    'bic' => $account->bic(),
                    'label' => $account->label(),
                    'primary' => $account->isPrimary() ? ['label' => $this->translator->trans('party.contact.primary'), 'tone' => 'green'] : null,
                ],
                'actions' => $actions,
            ];
        }, $accounts);
    }

    /**
     * @return list<array{time: string, datetime: string, icon: string, text: string}>
     */
    private function timeline(Party $party): array
    {
        return array_map(function (PartyHistoryEntry $entry): array {
            $fields = [];
            foreach ($entry->fields as $field) {
                // Embedded values (address.street, invoicingDefaults.currency) are reported as the embedded value.
                $field = explode('.', $field)[0];
                if (\in_array($field, self::QUIET_FIELDS, true)) {
                    continue;
                }
                // A custom field of a module's extension of the contact (ADR 0045) by its label.
                $custom = null === $entry->entityClass ? null : $this->fieldProperties->definitionOf($entry->entityClass, $field);
                if ($custom instanceof \Foresi\Shared\Contract\Field\FieldDefinition) {
                    $fields[$field] = $this->translator->trans($custom->label);

                    continue;
                }
                $key = 'party.field.'.$field;
                $label = $this->translator->trans($key);
                $fields[$field] = $label === $key ? $field : $label;
            }

            return [
                'time' => $entry->occurredAt->format($this->translator->trans('format.datetime')),
                'datetime' => $entry->occurredAt->format(\DATE_ATOM),
                'icon' => match ($entry->action) {
                    'created' => 'plus',
                    'deleted' => 'x',
                    default => 'pencil',
                },
                'text' => $this->translator->trans('party.history.entry', [
                    'action' => $this->translator->trans('party.history.action.'.$entry->action),
                    'subject' => $this->translator->trans('party.history.subject.'.$entry->subject),
                    'fields' => implode(', ', $fields),
                ]),
            ];
        }, $this->details->history($party));
    }
}
