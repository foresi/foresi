<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Infrastructure\Controller;

use Foresi\Audit\Application\AuditActor;
use Foresi\Import\Application\ExportRecords;
use Foresi\Import\Application\Imports;
use Foresi\Import\Application\Source\SourceFormat;
use Foresi\Import\Domain\Import;
use Foresi\Party\Application\ArchiveParty;
use Foresi\Party\Application\ImportedBy;
use Foresi\Party\Application\LinkContactPerson;
use Foresi\Party\Application\PartyDetails;
use Foresi\Party\Application\PartyHistoryEntry;
use Foresi\Party\Application\PartyPage;
use Foresi\Party\Application\PartyQuery;
use Foresi\Party\Application\PartyRefused;
use Foresi\Party\Application\PartyRow;
use Foresi\Party\Application\PartyRules;
use Foresi\Party\Application\Query\ContactDetails;
use Foresi\Party\Application\Query\ContactList;
use Foresi\Party\Application\RefreshRegistryChecks;
use Foresi\Party\Application\TransactionalWork;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyAddress;
use Foresi\Party\Domain\PartyBankAccount;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PartyRole;
use Foresi\Party\Domain\RegistryCheck;
use Foresi\Party\Domain\RegistryResult;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Infrastructure\Column\ContactColumns;
use Foresi\Party\Infrastructure\Form\PartySubmissionReader;
use Foresi\Party\Infrastructure\Http\WebsiteLink;
use Foresi\Shared\Domain\Id;
use Foresi\Ui\Column\ListColumnRegistry;
use Foresi\Ui\Component\DataTable;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
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

    private const array VIEWS = ['all', 'customers', 'suppliers', 'archived'];
    private const array TABS = ['overview', 'contacts', 'addresses', 'bank-accounts', 'history'];
    private const int MAX_BULK = 1000;
    private const int MAX_SEARCH = 100;
    private const array QUIET_FIELDS = ['registrationKey', 'registrationCountry', 'billingFlag', 'primaryFlag', 'viesCheckedAt', 'unreliablePayerCheckedAt', 'unreliablePayerAccounts', 'party', 'company', 'person'];

    public function __construct(
        private readonly PartyQuery $parties,
        private readonly PartyDetails $details,
        private readonly ContactList $contacts,
        private readonly ContactDetails $contactDetails,
        private readonly ArchiveParty $archiveParty,
        private readonly LinkContactPerson $linkContactPerson,
        private readonly RefreshRegistryChecks $refreshChecks,
        private readonly TransactionalWork $transaction,
        private readonly ExportRecords $export,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
        private readonly AuditActor $actor,
        private readonly Imports $imports,
        #[Autowire(service: 'limiter.party_refresh_checks')]
        private readonly RateLimiterFactory $refreshLimiter,
        private readonly ListColumnRegistry $listColumns,
        private readonly ContactColumns $contactColumns,
    ) {
    }

    #[Route('/contacts', name: 'app_contacts', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $view = $request->query->getString('view', 'all');
        $view = \in_array($view, self::VIEWS, true) ? $view : 'all';
        $text = trim(mb_substr($request->query->getString('q'), 0, self::MAX_SEARCH));
        $sort = $request->query->getString('sort', 'name');
        $sort = \in_array(ltrim($sort, '-'), PartyQuery::SORTS, true) ? $sort : 'name';
        $role = match ($view) {
            'customers' => PartyRole::Customer,
            'suppliers' => PartyRole::Supplier,
            default => null,
        };

        [$importedBy, $import] = $this->importedBy($request->query);
        $page = $this->contacts->search($text, $role, 'archived' === $view, $sort, max(1, $request->query->getInt('page', 1)), $importedBy);
        $counts = $this->contacts->counts();
        $query = ['view' => 'all' === $view ? null : $view, 'q' => '' === $text ? null : $text, 'sort' => 'name' === $sort ? null : $sort]
            + ($importedBy instanceof ImportedBy ? ['import' => $importedBy->importId, 'outcome' => $importedBy->outcome] : []);

        return $this->render('party/index.html.twig', [
            'firstRun' => 0 === $counts->all && 0 === $counts->archived,
            'view' => $view,
            'text' => $text,
            'sort' => $sort,
            'page' => $page,
            'tabs' => $this->tabs($view, $text, $counts->all, $counts->customers, $counts->suppliers, $counts->archived),
            'columns' => $this->columns($sort, $query),
            'rows' => $this->rows($page),
            'baseUrl' => $this->generateUrl('app_contacts', $query),
            'filtered' => '' !== $text || 'all' !== $view || $importedBy instanceof ImportedBy,
            'importedBy' => $importedBy instanceof ImportedBy && $import instanceof Import ? ['text' => $this->translator->trans('party.imported_by.'.$importedBy->outcome, ['name' => $import->originalName()]), 'href' => $this->generateUrl('app_import_show', ['id' => $importedBy->importId])] : null,
            'exportQuery' => ['view' => $view, 'q' => $text] + ($importedBy instanceof ImportedBy ? ['import' => $importedBy->importId, 'outcome' => $importedBy->outcome] : []),
        ]);
    }

    #[Route('/contacts/{id}', name: 'app_contact_show', requirements: ['id' => Requirement::UUID_V7], methods: ['GET'])]
    public function show(string $id, Request $request): Response
    {
        $tab = $request->query->getString('tab', 'overview');

        return $this->detail($this->contactDetails->party($id), $id, \in_array($tab, self::TABS, true) ? $tab : 'overview', $request->getLocale());
    }

    /**
     * @param array<string, mixed> $extra what the contacts tab shows after a refused link
     */
    private function detail(Party $party, string $id, string $tab, string $locale, array $extra = [], int $status = Response::HTTP_OK): Response
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
            'tabs' => $this->detailTabs($party, $tab),
            'kind' => $this->translator->trans('party.kind.'.$party->kind()->value),
            'roles' => array_map(fn (PartyRole $role): string => $this->translator->trans('party.role.'.$role->value), $party->roles()),
            'facts' => $this->facts($party),
            'checks' => $this->checks($vies, $reliability),
            'accounts' => $unreliable ? $party->publishedBankAccounts() : [],
            'hasVatId' => $vatId instanceof VatId,
            'note' => $party->privateNote(),
            'defaults' => $this->defaults($party),
            'locale' => $locale,
        ];

        return $this->render('party/show.html.twig', $extra + $data + match ($tab) {
            'contacts' => $this->contactsTab($party),
            'addresses' => ['addressRows' => $this->addressRows($this->details->addresses($party))],
            'bank-accounts' => ['accountRows' => $this->accountRows($this->details->bankAccounts($party))],
            'history' => ['timeline' => $this->timeline($party)],
            default => ['billing' => $this->details->billingAddress($party)?->address()],
        }, new Response(status: $status));
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

    /**
     * Exports the selected contacts, or the whole view as it is filtered, through
     * the import target: the file has the columns the import maps and imports
     * again. Unknown ids are ignored. The file name carries the date
     * only, never a name or a number. It is streamed, so a large address book
     * needs no limit.
     */
    #[Route('/contacts/export', name: 'app_contacts_export', methods: ['POST'])]
    public function export(Request $request): Response
    {
        $this->checkToken($request);
        $format = 'xlsx' === $request->request->getString('format') ? SourceFormat::Xlsx : SourceFormat::Csv;
        // The header action exports the whole view as it is filtered, not a selection.
        if ('view' === $request->request->getString('scope')) {
            $view = $request->request->getString('view', 'all');
            $role = match ($view) {
                'customers' => PartyRole::Customer,
                'suppliers' => PartyRole::Supplier,
                default => null,
            };
            // A list narrowed to an import exports exactly what it shows.
            [$importedBy] = $this->importedBy($request->request);
            $ids = $this->parties->matchingIds(trim(mb_substr($request->request->getString('q'), 0, self::MAX_SEARCH)), $role, 'archived' === $view, $importedBy);
        } else {
            $selected = $this->selectedIds($request);
            $found = [] === $selected ? [] : $this->parties->findMany($selected);
            if ([] === $found) {
                if ([] === $selected) {
                    return $this->redirectToRoute('app_contacts');
                }
                throw new NotFoundHttpException();
            }
            $ids = array_map(static fn (Party $party): string => $party->id(), $found);
        }
        if ([] === $ids) {
            return $this->redirectToRoute('app_contacts');
        }

        $response = new StreamedResponse(function () use ($ids, $format): void {
            $this->export->write('party', $ids, $format, 'php://output');
        });
        $response->headers->set('Content-Type', SourceFormat::Xlsx === $format ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, 'contacts-'.$this->clock->now()->format('Ymd').'.'.$format->value));
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    #[Route('/contacts/{id}/link', name: 'app_contact_link', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function link(string $id, Request $request): Response
    {
        $this->checkToken($request);
        $company = $this->party($id);
        $personId = $request->request->getString('person');
        $position = trim($request->request->getString('position'));
        $primary = $request->request->getBoolean('primary');
        // A person the select never offered (or none at all) is not found, as everywhere.
        $person = '' === $personId ? null : $this->party($personId);

        $errors = [];
        if (!$person instanceof Party) {
            $errors['person'] = 'party.contacts.person_invalid';
            // Without a person the rules never run, so the length is told here too.
            if (mb_strlen($position) > PartyRules::MAX_POSITION) {
                $errors['position'] = PartySubmissionReader::MESSAGES[PartyRefused::TOO_LONG];
            }
        } else {
            try {
                $this->linkContactPerson->link($company, $person, $position, $primary);
            } catch (PartyRefused $refused) {
                // The rules name the person by its id; the form has a field "person".
                $errors['personId' === $refused->field ? 'person' : (string) $refused->field] = PartySubmissionReader::MESSAGES[$refused->reason] ?? 'party.contacts.person_invalid';
            }
        }
        if ([] !== $errors) {
            // Shown again with every problem at its field and what was entered kept.
            return $this->detail($company, $id, 'contacts', $request->getLocale(), [
                'linkErrors' => array_map(fn (string $key): string => $this->translator->trans($key), $errors),
                'linkValues' => ['person' => $personId, 'position' => $position, 'primary' => $primary],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->addFlash('success', $this->translator->trans('party.flash.linked'));

        return $this->redirectToRoute('app_contact_show', ['id' => $id, 'tab' => 'contacts']);
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
     * @param InputBag<string> $input the query of the list, or the form of its export
     *
     * @return array{?ImportedBy, ?Import}
     */
    private function importedBy(InputBag $input): array
    {
        $id = $input->getString('import');
        $outcome = $input->getString('outcome', 'create');
        if (!Id::isValid($id) || !\in_array($outcome, ImportedBy::OUTCOMES, true)) {
            return [null, null];
        }
        // An unknown import is not found, so its id narrows nothing.
        $import = $this->imports->find($id);

        return $import instanceof Import ? [new ImportedBy($id, $outcome), $import] : [null, null];
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
        return $this->listColumns->display(ContactColumns::LIST)->table($sort, fn (string $next): string => $this->generateUrl('app_contacts', ['sort' => $next, 'page' => null] + $query));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(PartyPage $page): array
    {
        $display = $this->listColumns->display(ContactColumns::LIST);

        return array_map(function (PartyRow $row) use ($display): array {
            $cells = $display->cells($row);

            return [
                'id' => $row->id,
                'label' => $row->displayName,
                'href' => $this->generateUrl('app_contact_show', ['id' => $row->id]),
                // The contact opens in the side sheet over the list (docs/design/overlays.md).
                'opens' => DataTable::OPENS_SHEET,
                'secondary' => implode(' · ', array_filter([$this->contactColumns->roles($row), $row->registrationNumber, $row->email], static fn (?string $part): bool => null !== $part && '' !== $part)),
                'cells' => $cells,
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
                'href' => $this->generateUrl('app_contact_show', ['id' => $party->id(), 'tab' => 'overview' === $tab ? null : $tab]),
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
        $facts = [
            ['label' => $this->translator->trans('party.field.registrationNumber'), 'value' => $party->registrationNumber()->value ?? $dash],
            ['label' => $this->translator->trans('party.field.vatId'), 'value' => $party->vatId()?->toString() ?? $dash],
            ['label' => $this->translator->trans('party.field.email'), 'value' => $this->details->generalEmail($party)?->address() ?? $dash],
            ['label' => $this->translator->trans('party.field.phone'), 'value' => '' === $party->phone() ? $dash : $party->phone()],
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
            $rows[] = [
                'id' => $link->id(),
                'href' => $this->generateUrl('app_contact_show', ['id' => $other->id()]),
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
                $candidates[$person->id()] = $person->displayName();
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
            'id' => $address->id(),
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
            'id' => $account->id(),
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
}
