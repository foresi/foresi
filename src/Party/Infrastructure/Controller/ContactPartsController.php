<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Infrastructure\Controller;

use Foresi\Party\Application\ContactPartRefused;
use Foresi\Party\Application\LinkContactPerson;
use Foresi\Party\Application\ManageContactPersons;
use Foresi\Party\Application\ManagePartyAddresses;
use Foresi\Party\Application\ManagePartyBankAccounts;
use Foresi\Party\Application\PartyDetails;
use Foresi\Party\Application\PartyQuery;
use Foresi\Party\Application\PartyRefused;
use Foresi\Party\Application\UnknownParty;
use Foresi\Party\Application\UnlinkContactPerson;
use Foresi\Party\Domain\AddressType;
use Foresi\Party\Domain\ContactLink;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyAddress;
use Foresi\Party\Domain\PartyBankAccount;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Infrastructure\Form\CountryOptions;
use Foresi\Party\Infrastructure\Form\PartySubmissionReader;
use Foresi\Shared\Domain\Id;
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
 * What the tabs of a contact add and change: its contact persons, bank
 * accounts and addresses. Adding and editing are small dialog forms over the
 * contact (docs/design/overlays.md), and the same forms as pages without
 * JavaScript; the row actions post and come back to the tab.
 */
final class ContactPartsController extends AbstractController
{
    public const string FORM_TOKEN = 'contact_part';
    public const string MODE_EXISTING = 'existing';
    public const string MODE_NEW = 'new';

    private const array IDS = ['id' => Requirement::UUID_V7];
    private const array PART_IDS = ['id' => Requirement::UUID_V7, 'part' => Requirement::UUID_V7];
    /** The contact's tab every form and action comes back to. */
    private const array TABS = ['person' => 'contacts', 'account' => 'bank-accounts', 'address' => 'addresses'];
    private const array MESSAGES = [
        ContactPartRefused::REQUIRED => 'party.error.required',
        ContactPartRefused::TOO_LONG => 'party.error.too_long',
        ContactPartRefused::EMAIL => 'party.error.email',
        ContactPartRefused::COUNTRY => 'party.error.country',
        ContactPartRefused::ACCOUNT_NUMBER => 'party.accounts.error.number',
        ContactPartRefused::BIC => 'party.accounts.error.bic',
        ContactPartRefused::DUPLICATE_ACCOUNT => 'party.accounts.error.duplicate',
        ContactPartRefused::ADDRESS_TYPE => 'party.error.choice',
    ];

    public function __construct(
        private readonly PartyQuery $parties,
        private readonly PartyDetails $details,
        private readonly LinkContactPerson $linkPerson,
        private readonly UnlinkContactPerson $unlinkPerson,
        private readonly ManageContactPersons $persons,
        private readonly ManagePartyBankAccounts $accounts,
        private readonly ManagePartyAddresses $addresses,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/contacts/{id}/persons/new', name: 'app_contact_person_new', requirements: self::IDS, methods: ['GET', 'POST'])]
    public function newPerson(string $id, Request $request): Response
    {
        $company = $this->company($id);
        $candidates = $this->candidates($company);
        $hasPrimary = array_any($this->details->contactLinks($company), static fn (ContactLink $link): bool => $link->company() === $company && $link->isPrimary());
        $mode = $request->isMethod('POST') ? $request->request->getString('mode') : $request->query->getString('mode');
        // Existing first while the address book has a person to offer, else a new one.
        $mode = \in_array($mode, [self::MODE_EXISTING, self::MODE_NEW], true) ? $mode : ([] === $candidates ? self::MODE_NEW : self::MODE_EXISTING);
        $values = ['person' => '', 'name' => '', 'email' => '', 'phone' => '', 'position' => '', 'primary' => !$hasPrimary];
        if (!$request->isMethod('POST')) {
            return $this->personForm($company, $mode, $candidates, $values, [], Response::HTTP_OK);
        }

        $this->checkToken($request, self::FORM_TOKEN);
        $values = [
            'person' => $request->request->getString('person'),
            'name' => trim($request->request->getString('name')),
            'email' => trim($request->request->getString('email')),
            'phone' => trim($request->request->getString('phone')),
            'position' => trim($request->request->getString('position')),
            'primary' => $request->request->getBoolean('primary'),
        ];
        try {
            if (self::MODE_NEW === $mode) {
                $this->persons->addNew($company, $values['name'], $values['email'], $values['phone'], $values['position'], $values['primary']);
                $this->addFlash('success', $this->translator->trans('party.flash.person_created'));
            } else {
                $this->linkPerson->link($company, $this->person($values['person']), $values['position'], $values['primary']);
                $this->addFlash('success', $this->translator->trans('party.flash.linked'));
            }
        } catch (ContactPartRefused $refused) {
            return $this->personForm($company, $mode, $candidates, $values, $this->messages($refused), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (PartyRefused $refused) {
            // The rules name the person by its id; the form has a field "person".
            $field = 'personId' === $refused->field ? 'person' : (string) $refused->field;
            $key = 'person' === $field ? 'party.contacts.person_invalid' : (PartySubmissionReader::MESSAGES[$refused->reason] ?? 'party.error.save_failed');

            return $this->personForm($company, $mode, $candidates, $values, [$field => $this->translator->trans($key)], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->back($company, 'person');
    }

    #[Route('/contacts/{id}/persons/{part}/primary', name: 'app_contact_person_primary', requirements: self::PART_IDS, methods: ['POST'])]
    public function primaryPerson(string $id, string $part, Request $request): RedirectResponse
    {
        $this->checkToken($request, PartyController::ACTION_TOKEN);
        $company = $this->company($id);
        try {
            $this->persons->makePrimary($company, $part);
        } catch (PartyRefused) {
            throw new NotFoundHttpException();
        }
        $this->addFlash('success', $this->translator->trans('party.flash.person_primary'));

        return $this->back($company, 'person');
    }

    #[Route('/contacts/{id}/persons/{part}/unlink', name: 'app_contact_person_unlink', requirements: self::PART_IDS, methods: ['POST'])]
    public function unlinkPerson(string $id, string $part, Request $request): RedirectResponse
    {
        $this->checkToken($request, PartyController::ACTION_TOKEN);
        $company = $this->company($id);
        $link = array_find($this->details->contactLinks($company), static fn (ContactLink $link): bool => $link->id() === $part && $link->company() === $company)
            ?? throw new NotFoundHttpException();
        $this->unlinkPerson->unlink($company, $link->person());
        $this->addFlash('success', $this->translator->trans('party.flash.unlinked', ['name' => $link->person()->displayName()]));

        return $this->back($company, 'person');
    }

    #[Route('/contacts/{id}/bank-accounts/new', name: 'app_contact_account_new', requirements: self::IDS, methods: ['GET', 'POST'])]
    public function newAccount(string $id, Request $request): Response
    {
        $party = $this->party($id);
        $values = ['number' => '', 'bic' => '', 'label' => '', 'primary' => [] === $this->details->bankAccounts($party)];
        if (!$request->isMethod('POST')) {
            return $this->accountForm($party, null, $values, [], Response::HTTP_OK);
        }

        $this->checkToken($request, self::FORM_TOKEN);
        $values = $this->accountValues($request);
        try {
            $this->accounts->add($party, $values['number'], $values['bic'], $values['label'], $values['primary']);
        } catch (ContactPartRefused $refused) {
            return $this->accountForm($party, null, $values, $this->messages($refused), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->addFlash('success', $this->translator->trans('party.flash.account_added'));

        return $this->back($party, 'account');
    }

    #[Route('/contacts/{id}/bank-accounts/{part}/edit', name: 'app_contact_account_edit', requirements: self::PART_IDS, methods: ['GET', 'POST'])]
    public function editAccount(string $id, string $part, Request $request): Response
    {
        $party = $this->party($id);
        $account = $this->account($party, $part);
        $values = ['number' => $account->iban()->toString(), 'bic' => (string) $account->bic(), 'label' => $account->label(), 'primary' => $account->isPrimary()];
        if (!$request->isMethod('POST')) {
            return $this->accountForm($party, $account, $values, [], Response::HTTP_OK);
        }

        $this->checkToken($request, self::FORM_TOKEN);
        $values = ['number' => $values['number']] + $this->accountValues($request);
        try {
            $this->accounts->change($party, $part, $values['bic'], $values['label']);
            if ($values['primary']) {
                $this->accounts->makePrimary($party, $part);
            }
        } catch (ContactPartRefused $refused) {
            return $this->accountForm($party, $account, $values, $this->messages($refused), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->addFlash('success', $this->translator->trans('party.flash.account_changed'));

        return $this->back($party, 'account');
    }

    #[Route('/contacts/{id}/bank-accounts/{part}/primary', name: 'app_contact_account_primary', requirements: self::PART_IDS, methods: ['POST'])]
    public function primaryAccount(string $id, string $part, Request $request): RedirectResponse
    {
        $this->checkToken($request, PartyController::ACTION_TOKEN);
        $party = $this->party($id);
        $this->account($party, $part);
        $this->accounts->makePrimary($party, $part);
        $this->addFlash('success', $this->translator->trans('party.flash.account_primary'));

        return $this->back($party, 'account');
    }

    #[Route('/contacts/{id}/bank-accounts/{part}/delete', name: 'app_contact_account_delete', requirements: self::PART_IDS, methods: ['POST'])]
    public function deleteAccount(string $id, string $part, Request $request): RedirectResponse
    {
        $this->checkToken($request, PartyController::ACTION_TOKEN);
        $party = $this->party($id);
        $this->account($party, $part);
        $this->accounts->remove($party, $part);
        $this->addFlash('success', $this->translator->trans('party.flash.account_deleted'));

        return $this->back($party, 'account');
    }

    #[Route('/contacts/{id}/addresses/new', name: 'app_contact_address_new', requirements: self::IDS, methods: ['GET', 'POST'])]
    public function newAddress(string $id, Request $request): Response
    {
        $party = $this->party($id);
        $country = $this->details->billingAddress($party)?->address()->countryCode ?? '';
        $values = ['type' => AddressType::Delivery->value, 'label' => '', 'street' => '', 'city' => '', 'postalCode' => '', 'countryCode' => $country];
        if (!$request->isMethod('POST')) {
            return $this->addressForm($request, $party, null, $values, [], Response::HTTP_OK);
        }

        $this->checkToken($request, self::FORM_TOKEN);
        $values = $this->addressValues($request);
        try {
            $this->addresses->add($party, $values['type'], $values['street'], $values['city'], $values['postalCode'], $values['countryCode'], $values['label']);
        } catch (ContactPartRefused $refused) {
            return $this->addressForm($request, $party, null, $values, $this->messages($refused), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->addFlash('success', $this->translator->trans('party.flash.address_added'));

        return $this->back($party, 'address');
    }

    #[Route('/contacts/{id}/addresses/{part}/edit', name: 'app_contact_address_edit', requirements: self::PART_IDS, methods: ['GET', 'POST'])]
    public function editAddress(string $id, string $part, Request $request): Response
    {
        $party = $this->party($id);
        $address = $this->address($party, $part);
        $place = $address->address();
        $values = ['type' => $address->type()->value, 'label' => $address->label(), 'street' => $place->street, 'city' => $place->city, 'postalCode' => $place->postalCode, 'countryCode' => $place->countryCode];
        if (!$request->isMethod('POST')) {
            return $this->addressForm($request, $party, $address, $values, [], Response::HTTP_OK);
        }

        $this->checkToken($request, self::FORM_TOKEN);
        $values = $this->addressValues($request);
        try {
            $this->addresses->change($party, $part, $values['type'], $values['street'], $values['city'], $values['postalCode'], $values['countryCode'], $values['label']);
        } catch (ContactPartRefused $refused) {
            return $this->addressForm($request, $party, $address, $values, $this->messages($refused), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->addFlash('success', $this->translator->trans('party.flash.address_changed'));

        return $this->back($party, 'address');
    }

    #[Route('/contacts/{id}/addresses/{part}/delete', name: 'app_contact_address_delete', requirements: self::PART_IDS, methods: ['POST'])]
    public function deleteAddress(string $id, string $part, Request $request): RedirectResponse
    {
        $this->checkToken($request, PartyController::ACTION_TOKEN);
        $party = $this->party($id);
        $this->address($party, $part);
        try {
            $this->addresses->remove($party, $part);
        } catch (ContactPartRefused) {
            // The billing address offers no delete; a hand-made request gets the tab back unchanged.
            $this->addFlash('error', $this->translator->trans('party.addresses.billing_kept'));

            return $this->back($party, 'address');
        }
        $this->addFlash('success', $this->translator->trans('party.flash.address_deleted'));

        return $this->back($party, 'address');
    }

    /**
     * @param array<string, string>      $candidates
     * @param array<string, string|bool> $values
     * @param array<string, string>      $errors     field => message
     */
    private function personForm(Party $company, string $mode, array $candidates, array $values, array $errors, int $status): Response
    {
        return $this->render('@Party/parts/person.html.twig', [
            'party' => $company,
            'mode' => $mode,
            'candidates' => $candidates,
            'values' => $values,
            'errors' => $errors,
            'modes' => [
                ['label' => $this->translator->trans('party.contacts.mode.existing'), 'href' => $this->generateUrl('app_contact_person_new', ['id' => $company->id(), 'mode' => self::MODE_EXISTING]), 'active' => self::MODE_EXISTING === $mode],
                ['label' => $this->translator->trans('party.contacts.mode.new'), 'href' => $this->generateUrl('app_contact_person_new', ['id' => $company->id(), 'mode' => self::MODE_NEW]), 'active' => self::MODE_NEW === $mode],
            ],
            'action' => $this->generateUrl('app_contact_person_new', ['id' => $company->id()]),
            'cancel' => $this->tabUrl($company, 'person'),
        ], new Response(status: $status));
    }

    /**
     * @param array<string, string|bool> $values
     * @param array<string, string>      $errors field => message
     */
    private function accountForm(Party $party, ?PartyBankAccount $account, array $values, array $errors, int $status): Response
    {
        return $this->render('@Party/parts/account.html.twig', [
            'party' => $party,
            'account' => $account,
            'iban' => $account instanceof PartyBankAccount ? trim(chunk_split($account->iban()->toString(), 4, ' ')) : null,
            'values' => $values,
            'errors' => $errors,
            'action' => $account instanceof PartyBankAccount
                ? $this->generateUrl('app_contact_account_edit', ['id' => $party->id(), 'part' => $account->id()])
                : $this->generateUrl('app_contact_account_new', ['id' => $party->id()]),
            'cancel' => $this->tabUrl($party, 'account'),
        ], new Response(status: $status));
    }

    /**
     * @param array<string, string> $values
     * @param array<string, string> $errors field => message
     */
    private function addressForm(Request $request, Party $party, ?PartyAddress $address, array $values, array $errors, int $status): Response
    {
        $types = [];
        foreach (AddressType::cases() as $type) {
            if (AddressType::Billing !== $type) {
                $types[$type->value] = $this->translator->trans('party.address_type.'.$type->value);
            }
        }

        return $this->render('@Party/parts/address.html.twig', [
            'party' => $party,
            'address' => $address,
            'billing' => AddressType::Billing === $address?->type(),
            'types' => $types,
            'countries' => CountryOptions::options($request->getLocale()),
            'values' => $values,
            'errors' => $errors,
            'action' => $address instanceof PartyAddress
                ? $this->generateUrl('app_contact_address_edit', ['id' => $party->id(), 'part' => $address->id()])
                : $this->generateUrl('app_contact_address_new', ['id' => $party->id()]),
            'cancel' => $this->tabUrl($party, 'address'),
        ], new Response(status: $status));
    }

    /**
     * @return array{number: string, bic: string, label: string, primary: bool}
     */
    private function accountValues(Request $request): array
    {
        return [
            'number' => trim($request->request->getString('number')),
            'bic' => trim($request->request->getString('bic')),
            'label' => trim($request->request->getString('label')),
            'primary' => $request->request->getBoolean('primary'),
        ];
    }

    /**
     * @return array{type: string, label: string, street: string, city: string, postalCode: string, countryCode: string}
     */
    private function addressValues(Request $request): array
    {
        return [
            'type' => $request->request->getString('type'),
            'label' => trim($request->request->getString('label')),
            'street' => trim($request->request->getString('street')),
            'city' => trim($request->request->getString('city')),
            'postalCode' => trim($request->request->getString('postalCode')),
            'countryCode' => strtoupper(trim($request->request->getString('countryCode'))),
        ];
    }

    /**
     * @return array<string, string> field => message
     */
    private function messages(ContactPartRefused $refused): array
    {
        return array_map(fn (string $reason): string => $this->translator->trans(self::MESSAGES[$reason] ?? 'party.error.save_failed'), $refused->reasons);
    }

    /**
     * The persons of the address book the company does not list yet, by id.
     *
     * @return array<string, string>
     */
    private function candidates(Party $company): array
    {
        $candidates = [];
        foreach ($this->parties->contactPersonCandidates($company) as $person) {
            $candidates[$person->id()] = $person->displayName();
        }

        return $candidates;
    }

    private function back(Party $party, string $part): RedirectResponse
    {
        return $this->redirect($this->tabUrl($party, $part));
    }

    private function tabUrl(Party $party, string $part): string
    {
        return $this->generateUrl('app_contact_show', ['id' => $party->id(), 'tab' => self::TABS[$part]]);
    }

    private function party(string $id): Party
    {
        return Id::isValid($id) ? ($this->parties->find($id) ?? throw new NotFoundHttpException()) : throw new NotFoundHttpException();
    }

    /**
     * A company or sole trader: a person has no contact persons of its own.
     */
    private function company(string $id): Party
    {
        $party = $this->party($id);

        return PartyKind::Person === $party->kind() ? throw new NotFoundHttpException() : $party;
    }

    /**
     * The chosen person; one the select never offered, or none, is refused at its field.
     *
     * @throws PartyRefused
     */
    private function person(string $id): Party
    {
        $person = Id::isValid($id) ? $this->parties->find($id) : null;

        return $person ?? throw new PartyRefused(PartyRefused::PERSON, 'personId');
    }

    private function account(Party $party, string $id): PartyBankAccount
    {
        try {
            return $this->accounts->account($party, $id);
        } catch (UnknownParty) {
            throw new NotFoundHttpException();
        }
    }

    private function address(Party $party, string $id): PartyAddress
    {
        try {
            return $this->addresses->stored($party, $id);
        } catch (UnknownParty) {
            throw new NotFoundHttpException();
        }
    }

    private function checkToken(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
