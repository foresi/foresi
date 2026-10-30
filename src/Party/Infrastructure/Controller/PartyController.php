<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Infrastructure\Controller;

use App\Party\Application\ArchiveParty;
use App\Party\Application\LinkContactPerson;
use App\Party\Application\PartyDetails;
use App\Party\Application\PartyHistoryEntry;
use App\Party\Application\PartyPage;
use App\Party\Application\PartyQuery;
use App\Party\Application\PartyRow;
use App\Party\Application\RefreshRegistryChecks;
use App\Party\Application\TransactionalWork;
use App\Party\Application\VatPayerReliability;
use App\Party\Domain\Party;
use App\Party\Domain\PartyAddress;
use App\Party\Domain\PartyBankAccount;
use App\Party\Domain\PartyKind;
use App\Party\Domain\PartyRole;
use App\Party\Domain\RegistryCheck;
use App\Party\Domain\RegistryResult;
use App\Party\Domain\VatId;
use App\Party\Infrastructure\Http\PartyCsvExport;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The address book: list, detail and the actions on existing contacts. A
 * contact of another tenant is never found, so it answers 404 exactly like a
 * contact that does not exist.
 */
final class PartyController extends AbstractController
{
    public const string ACTION_TOKEN = 'party_action';

    private const array VIEWS = ['all', 'customers', 'suppliers', 'archived'];
    private const array TABS = ['overview', 'contacts', 'addresses', 'bank-accounts', 'history'];
    private const int MAX_BULK = 1000;
    private const array QUIET_FIELDS = ['registrationKey', 'registrationCountry', 'billingFlag', 'primaryFlag', 'viesCheckedAt', 'unreliablePayerCheckedAt', 'party', 'company', 'person'];

    public function __construct(
        private readonly PartyQuery $parties,
        private readonly PartyDetails $details,
        private readonly ArchiveParty $archiveParty,
        private readonly LinkContactPerson $linkContactPerson,
        private readonly RefreshRegistryChecks $refreshChecks,
        private readonly VatPayerReliability $reliability,
        private readonly TransactionalWork $transaction,
        private readonly PartyCsvExport $export,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/contacts', name: 'app_contacts', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $view = $request->query->getString('view', 'all');
        $view = \in_array($view, self::VIEWS, true) ? $view : 'all';
        $text = trim($request->query->getString('q'));
        $sort = $request->query->getString('sort', 'name');
        $sort = \in_array(ltrim($sort, '-'), PartyQuery::SORTS, true) ? $sort : 'name';
        $role = match ($view) {
            'customers' => PartyRole::Customer,
            'suppliers' => PartyRole::Supplier,
            default => null,
        };

        $page = $this->parties->search($text, $role, 'archived' === $view, $sort, max(1, $request->query->getInt('page', 1)));
        $counts = $this->parties->counts();
        $query = ['view' => 'all' === $view ? null : $view, 'q' => '' === $text ? null : $text, 'sort' => 'name' === $sort ? null : $sort];

        return $this->render('party/index.html.twig', [
            'counts' => $counts,
            'firstRun' => 0 === $counts->all && 0 === $counts->archived,
            'view' => $view,
            'text' => $text,
            'sort' => $sort,
            'page' => $page,
            'tabs' => $this->tabs($view, $text, $counts->all, $counts->customers, $counts->suppliers, $counts->archived),
            'columns' => $this->columns($sort, $query),
            'rows' => $this->rows($page),
            'baseUrl' => $this->generateUrl('app_contacts', $query),
            'filtered' => '' !== $text || 'all' !== $view,
        ]);
    }

    #[Route('/contacts/{id}', name: 'app_contact_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, Request $request): Response
    {
        $party = $this->party($id);
        $tab = $request->query->getString('tab', 'overview');
        $tab = \in_array($tab, self::TABS, true) ? $tab : 'overview';
        $locale = $request->getLocale();

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
            'tabs' => $this->detailTabs($party, $tab),
            'kind' => $this->translator->trans('party.kind.'.$party->kind()->value),
            'roles' => array_map(fn (PartyRole $role): string => $this->translator->trans('party.role.'.$role->value), $party->roles()),
            'facts' => $this->facts($party),
            'checks' => $this->checks($vies, $reliability),
            'accounts' => $unreliable && $vatId instanceof VatId ? $this->reliability->check($vatId)->publishedBankAccounts : [],
            'hasVatId' => $vatId instanceof VatId,
            'note' => $party->privateNote(),
            'defaults' => $this->defaults($party),
            'locale' => $locale,
        ];

        return $this->render('party/show.html.twig', $data + match ($tab) {
            'contacts' => $this->contactsTab($party),
            'addresses' => ['addressRows' => $this->addressRows($this->details->addresses($party))],
            'bank-accounts' => ['accountRows' => $this->accountRows($this->details->bankAccounts($party))],
            'history' => ['timeline' => $this->timeline($party)],
            default => ['billing' => $this->details->billingAddress($party)?->address(), 'email' => $this->details->generalEmail($party)?->address()],
        });
    }

    #[Route('/contacts/{id}/archive', name: 'app_contact_archive', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function archive(int $id, Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $this->archiveParty->archive([$this->party($id)]);
        $this->addFlash('success', $this->translator->trans('party.flash.archived'));

        return $this->redirectToRoute('app_contact_show', ['id' => $id]);
    }

    #[Route('/contacts/{id}/restore', name: 'app_contact_restore', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function restore(int $id, Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $this->archiveParty->restore([$this->party($id)]);
        $this->addFlash('success', $this->translator->trans('party.flash.restored'));

        return $this->redirectToRoute('app_contact_show', ['id' => $id]);
    }

    /**
     * Archives the selected contacts. Either all of them are contacts of the
     * active tenant, or nothing happens and the answer is 404.
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

    /**
     * Exports the selected contacts; ids of other tenants are ignored. The file
     * name carries the date only, never a name or a number.
     */
    #[Route('/contacts/export', name: 'app_contacts_export', methods: ['POST'])]
    public function export(Request $request): Response
    {
        $this->checkToken($request);
        $ids = $this->selectedIds($request);
        if ([] === $ids) {
            return $this->redirectToRoute('app_contacts');
        }
        $found = $this->parties->findMany($ids);
        if ([] === $found) {
            throw new NotFoundHttpException();
        }

        $header = array_map(fn (string $column): string => $this->translator->trans('party.export.'.$column), ['name', 'kind', 'roles', 'registration_number', 'vat_id', 'email', 'phone', 'street', 'city', 'postal_code', 'country']);
        $response = new Response($this->export->render($found, $header));
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, 'contacts-'.$this->clock->now()->format('Ymd').'.csv'));
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    #[Route('/contacts/{id}/link', name: 'app_contact_link', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function link(int $id, Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $company = $this->party($id);
        $person = $this->parties->find($request->request->getInt('person'));
        if (!$person instanceof Party) {
            throw new NotFoundHttpException();
        }

        $back = $this->redirectToRoute('app_contact_show', ['id' => $id, 'tab' => 'contacts']);
        if (PartyKind::Person === $company->kind() || PartyKind::Person !== $person->kind() || $this->alreadyLinked($company, $person)) {
            $this->addFlash('error', $this->translator->trans('party.flash.link_invalid'));

            return $back;
        }

        try {
            $this->linkContactPerson->link($company, $person, trim($request->request->getString('position')), $request->request->getBoolean('primary'));
        } catch (\DomainException) {
            $this->addFlash('error', $this->translator->trans('party.flash.link_invalid'));

            return $back;
        }
        $this->addFlash('success', $this->translator->trans('party.flash.linked'));

        return $back;
    }

    #[Route('/contacts/{id}/registry-checks', name: 'app_contact_refresh_checks', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function refreshChecks(int $id, Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $party = $this->party($id);
        $this->transaction->run(fn () => $this->refreshChecks->refresh($party));
        $this->addFlash('success', $this->translator->trans('party.flash.checks_refreshed'));

        return $this->redirectToRoute('app_contact_show', ['id' => $id]);
    }

    private function party(int $id): Party
    {
        return $this->parties->find($id) ?? throw new NotFoundHttpException();
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::ACTION_TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }

    /**
     * @return list<int>
     */
    private function selectedIds(Request $request): array
    {
        $ids = [];
        foreach ($request->request->all('ids') as $id) {
            if (\is_string($id) && ctype_digit($id)) {
                $ids[(int) $id] = (int) $id;
            }
        }

        return \array_slice(array_values($ids), 0, self::MAX_BULK);
    }

    private function alreadyLinked(Party $company, Party $person): bool
    {
        return array_any($this->details->contactLinks($company), fn (\App\Party\Domain\ContactLink $link): bool => $link->person() === $person);
    }

    /**
     * @return list<array{label: string, href: string, count: int, active: bool}>
     */
    private function tabs(string $view, string $text, int $all, int $customers, int $suppliers, int $archived): array
    {
        $tabs = [];
        foreach (['all' => $all, 'customers' => $customers, 'suppliers' => $suppliers, 'archived' => $archived] as $key => $count) {
            $tabs[] = [
                'label' => $this->translator->trans('party.view.'.$key),
                'href' => $this->generateUrl('app_contacts', ['view' => 'all' === $key ? null : $key, 'q' => '' === $text ? null : $text]),
                'count' => $count,
                'active' => $key === $view,
            ];
        }

        return $tabs;
    }

    /**
     * @param array<string, string|null> $query
     *
     * @return list<array<string, mixed>>
     */
    private function columns(string $sort, array $query): array
    {
        $columns = [];
        foreach (['name' => true, 'kind' => true, 'roles' => false, 'registration' => true, 'vat' => true, 'email' => false, 'status' => false] as $key => $sortable) {
            $column = ['key' => $key, 'label' => $this->translator->trans('party.column.'.$key)];
            if ($sortable) {
                $column += [
                    'sortable' => true,
                    'sort' => $sort === $key ? 'asc' : ($sort === '-'.$key ? 'desc' : null),
                    'href' => $this->generateUrl('app_contacts', ['sort' => $sort === $key ? '-'.$key : $key, 'page' => null] + $query),
                ];
            }
            $columns[] = $column;
        }

        return $columns;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(PartyPage $page): array
    {
        return array_map(function (PartyRow $row): array {
            $status = match (true) {
                $row->unreliablePayer => ['label' => $this->translator->trans('registry.result.unreliable'), 'tone' => 'red'],
                $row->archived => ['label' => $this->translator->trans('party.archived'), 'tone' => 'gray'],
                default => null,
            };

            return [
                'id' => (string) $row->id,
                'label' => $row->displayName,
                'href' => $this->generateUrl('app_contact_show', ['id' => $row->id]),
                'cells' => [
                    'name' => $row->displayName,
                    'kind' => $this->translator->trans('party.kind.'.$row->kind->value),
                    'roles' => implode(', ', array_map(fn (PartyRole $role): string => $this->translator->trans('party.role.'.$role->value), $row->roles)),
                    'registration' => $row->registrationNumber,
                    'vat' => $row->vatId,
                    'email' => $row->email,
                    'status' => $status,
                ],
            ];
        }, $page->rows);
    }

    /**
     * @return list<array{label: string, href: string, active: bool}>
     */
    private function detailTabs(Party $party, string $active): array
    {
        $tabs = [];
        foreach (self::TABS as $tab) {
            $tabs[] = [
                'label' => $this->translator->trans('party.tab.'.$tab),
                'href' => $this->generateUrl('app_contact_show', ['id' => $this->id($party), 'tab' => 'overview' === $tab ? null : $tab]),
                'active' => $tab === $active,
            ];
        }

        return $tabs;
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private function facts(Party $party): array
    {
        $dash = '—';

        return [
            ['label' => $this->translator->trans('party.field.registrationNumber'), 'value' => $party->registrationNumber()->value ?? $dash],
            ['label' => $this->translator->trans('party.field.vatId'), 'value' => $party->vatId()?->toString() ?? $dash],
            ['label' => $this->translator->trans('party.field.email'), 'value' => $this->details->generalEmail($party)?->address() ?? $dash],
            ['label' => $this->translator->trans('party.field.phone'), 'value' => '' === $party->phone() ? $dash : $party->phone()],
        ];
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
            $rows[] = [
                'id' => (string) $link->id(),
                'href' => $this->generateUrl('app_contact_show', ['id' => $this->id($other)]),
                'cells' => [
                    'name' => $other->displayName(),
                    'position' => $link->position(),
                    'primary' => $link->isPrimary() ? ['label' => $this->translator->trans('party.contact.primary'), 'tone' => 'green'] : null,
                ],
            ];
        }

        $candidates = [];
        if (PartyKind::Person !== $party->kind()) {
            foreach ($this->parties->contactPersonCandidates($party) as $person) {
                $candidates[(string) $this->id($person)] = $person->displayName();
            }
        }

        return ['contactRows' => $rows, 'candidates' => $candidates, 'isPerson' => $linked];
    }

    /**
     * @param list<PartyAddress> $addresses
     *
     * @return list<array<string, mixed>>
     */
    private function addressRows(array $addresses): array
    {
        return array_map(fn (PartyAddress $address): array => [
            'id' => (string) $address->id(),
            'cells' => [
                'type' => $this->translator->trans('party.address_type.'.$address->type()->value),
                'label' => $address->label(),
                'address' => implode(', ', array_filter([$address->address()->street, trim($address->address()->postalCode.' '.$address->address()->city), $address->address()->countryCode], static fn (string $part): bool => '' !== $part)),
            ],
        ], $addresses);
    }

    /**
     * @param list<PartyBankAccount> $accounts
     *
     * @return list<array<string, mixed>>
     */
    private function accountRows(array $accounts): array
    {
        return array_map(fn (PartyBankAccount $account): array => [
            'id' => (string) $account->id(),
            'cells' => [
                'iban' => $account->iban()->toString(),
                'bic' => $account->bic(),
                'label' => $account->label(),
                'primary' => $account->isPrimary() ? ['label' => $this->translator->trans('party.contact.primary'), 'tone' => 'green'] : null,
            ],
        ], $accounts);
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

    private function id(Party $party): int
    {
        return $party->id() ?? throw new \LogicException('The party is not stored.');
    }
}
