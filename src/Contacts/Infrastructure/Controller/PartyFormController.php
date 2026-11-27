<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Contacts\Infrastructure\Controller;

use Foresi\Contacts\Application\CreateParty;
use Foresi\Contacts\Application\UpdateParty;
use Foresi\Contacts\Infrastructure\Form\PartySubmission;
use Foresi\Contacts\Infrastructure\Form\PartySubmissionReader;
use Foresi\Party\Application\DuplicateFinder;
use Foresi\Party\Application\DuplicateMatch;
use Foresi\Party\Application\PartyDetails;
use Foresi\Party\Application\PartyInput;
use Foresi\Party\Application\PartyQuery;
use Foresi\Party\Contract\Field\ContactExtension;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PartyRole;
use Foresi\Party\Infrastructure\Form\CountryOptions;
use Foresi\Party\Infrastructure\Registry\RefreshRegistryChecksAfterResponse;
use Foresi\Shared\Application\Field\FieldRefused;
use Foresi\Shared\Application\TransactionalWork;
use Foresi\Shared\Infrastructure\Http\SubmissionTokens;
use Foresi\Ui\Contract\Dialog\DialogRequest;
use Foresi\Ui\Field\CustomFieldForm;
use Foresi\Ui\Field\CustomFieldSubmission;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Creating and editing contacts; the registry lookup that fills the form is
 * Party's (RegistryLookupController), which the firm's settings use too.
 * A new contact can also come from a short form in a dialog: over the invoice
 * form, a new customer whose id the page under it picks; over the contact list
 * (?for=contacts), a new contact of any role, whose page opens once it is saved.
 * The same URL without the dialog is the whole form, a page of its own.
 * The custom fields of a contact (ADR 0045) are in the whole form, and the
 * required ones in the short form too.
 *
 * Everything that can be wrong with a submission is found before a write
 * service is called, so a mistake is a form error. A write that still fails
 * (the domain has the last word) is caught and shown as a form error as well;
 * the unit of work leaves a usable persistence session behind.
 */
final class PartyFormController extends AbstractController
{
    public const string FORM_TOKEN = 'party_form';
    public const string CREATED_FLASH = 'contact_created';
    /** The query value of a new contact started from the contact list. */
    public const string FOR_CONTACTS = 'contacts';
    /** The values a new contact may be opened with from its URL, with the length each is cut to. */
    private const array PREFILL = ['displayName' => 255, 'registrationNumber' => 64, 'vatId' => 64];

    public function __construct(
        private readonly PartyQuery $parties,
        private readonly PartyDetails $details,
        private readonly CreateParty $createParty,
        private readonly UpdateParty $updateParty,
        private readonly DuplicateFinder $duplicates,
        private readonly PartySubmissionReader $reader,
        private readonly SubmissionTokens $tokens,
        private readonly TranslatorInterface $translator,
        private readonly TransactionalWork $transaction,
        private readonly CustomFieldForm $customFields,
    ) {
    }

    #[Route('/contacts/new', name: 'app_contact_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        if (!$request->isMethod('POST')) {
            return $this->form($request, null, $this->prefilled($request) + $this->defaults(), [], [], $this->tokens->issue(), Response::HTTP_OK, null);
        }

        $this->checkToken($request);
        $token = $request->request->getString('_submission');
        if ($this->tokens->isIssued($token) && null !== ($created = $this->tokens->createdId($token))) {
            // The same submission again: go to what it created.
            return $this->redirectToRoute('app_contact_show', ['id' => $created]);
        }

        $submission = $this->reader->read($request);
        if (!$this->tokens->isIssued($token)) {
            $token = $this->tokens->issue();
            $submission = new PartySubmission($submission->values, ['form' => 'party.error.expired'] + $submission->errors, null);
        }

        return $this->handle($request, null, $submission, $token);
    }

    #[Route('/contacts/{id}/edit', name: 'app_contact_edit', requirements: ['id' => Requirement::UUID_V7], methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request): Response
    {
        $party = $this->parties->find($id) ?? throw new NotFoundHttpException();
        if (!$request->isMethod('POST')) {
            return $this->form($request, $party, $this->valuesOf($party), [], [], '', Response::HTTP_OK, null);
        }

        $this->checkToken($request);

        return $this->handle($request, $party, $this->reader->read($request, $party->kind()), '');
    }

    private function handle(Request $request, ?Party $party, PartySubmission $submission, string $token): Response
    {
        $input = $submission->input;
        $custom = $this->customFields->read(ContactExtension::RECORD, $request, !$party instanceof Party, $this->quick($request, $party));
        if (!$submission->valid() || !$input instanceof PartyInput || !$custom->valid()) {
            return $this->form($request, $party, $submission->values, $submission->errors, [], $token, Response::HTTP_UNPROCESSABLE_ENTITY, $custom);
        }
        $input = $input->withCustomFields($custom->parsed);

        $matches = $this->duplicates->find($input->registrationNumber, $input->vatId, true, $party?->id());
        if ([] !== $matches && !$request->request->getBoolean('confirm_duplicate')) {
            return $this->form($request, $party, $submission->values, [], $matches, $token, Response::HTTP_UNPROCESSABLE_ENTITY, $custom);
        }

        try {
            [$id, $changedVat] = $this->transaction->run(function () use ($party, $input): array {
                if ($party instanceof Party) {
                    $changedVat = $party->vatId()?->toString() !== $input->vatId?->toString();
                    $this->updateParty->update($party, $input);
                    $saved = $party;
                } else {
                    $saved = $this->createParty->createFrom($input);
                    $changedVat = true;
                }

                return [$saved->id(), $changedVat];
            });
            if (!$party instanceof Party) {
                $this->tokens->complete($token, $id);
            }
        } catch (FieldRefused $refused) {
            return $this->form($request, $party, $submission->values, [], [], $token, Response::HTTP_UNPROCESSABLE_ENTITY, $custom->refused($refused->key, $this->translator->trans($refused->translationKey)));
        } catch (\DomainException) {
            return $this->form($request, $party, $submission->values, ['form' => 'contacts.error.save_failed'], [], $token, Response::HTTP_UNPROCESSABLE_ENTITY, $custom);
        }

        if ($changedVat && $input->vatId instanceof \Foresi\Party\Domain\VatId) {
            RefreshRegistryChecksAfterResponse::schedule($request, $id);
        }
        if (!$party instanceof Party && DialogRequest::is($request) && !$this->forContacts($request)) {
            // The page under the dialog takes the new contact: no message waits for another page.
            return new JsonResponse(['event' => 'contact:created', 'detail' => ['id' => $id], 'message' => $this->translator->trans('contacts.flash.created')]);
        }
        $this->addFlash('success', $this->translator->trans($party instanceof Party ? 'contacts.flash.updated' : 'contacts.flash.created'));
        if (!$party instanceof Party) {
            // The detail tells a form waiting in another tab, once.
            $this->addFlash(self::CREATED_FLASH, $id);
        }

        return $this->redirectToRoute('app_contact_show', ['id' => $id]);
    }

    /**
     * @param array<string, string|list<string>> $values
     * @param array<string, string>              $errors  field => translation key
     * @param list<DuplicateMatch>               $matches
     * @param CustomFieldSubmission|null         $custom  the custom fields posted; null shows the contact's own
     */
    private function form(Request $request, ?Party $party, array $values, array $errors, array $matches, string $token, int $status, ?CustomFieldSubmission $custom): Response
    {
        $locale = $request->getLocale();
        $messages = array_map(fn (string $key): string => $this->translator->trans($key), $errors);

        // A new contact from a dialog is the short form; the page is the whole one.
        $template = $this->quick($request, $party) ? '@Contacts/quick.html.twig' : '@Contacts/form.html.twig';

        $forContacts = !$party instanceof Party && $this->forContacts($request);

        return $this->render($template, [
            'party' => $party,
            'forContacts' => $forContacts,
            'values' => $values,
            'errors' => $messages,
            'duplicates' => array_map(fn (DuplicateMatch $match): array => [
                'name' => $match->displayName,
                'url' => $this->generateUrl('app_contact_show', ['id' => $match->partyId]),
                'archived' => $match->archived,
                'registration' => $match->sameRegistrationNumber,
                'vat' => $match->sameVatId,
            ], $matches),
            'token' => $token,
            'customValues' => $custom?->values,
            'customErrors' => $custom->errors ?? [],
            'kinds' => $this->kindOptions(),
            'roleOptions' => array_combine(array_map(static fn (PartyRole $role): string => $role->value, PartyRole::cases()), array_map(fn (PartyRole $role): string => $this->translator->trans('party.role.'.$role->value), PartyRole::cases())),
            'countries' => CountryOptions::options($locale),
            'action' => $party instanceof Party ? $this->generateUrl('app_contact_edit', ['id' => $party->id()]) : $this->generateUrl('app_contact_new', $forContacts ? ['for' => self::FOR_CONTACTS] : []),
            'cancel' => $party instanceof Party ? $this->generateUrl('app_contact_show', ['id' => $party->id()]) : $this->generateUrl('app_contacts'),
        ], new Response(status: $status));
    }

    /**
     * Whether the form is the short one of a new contact in a dialog, which asks only for the required custom fields.
     */
    private function quick(Request $request, ?Party $party): bool
    {
        return !$party instanceof Party && DialogRequest::is($request);
    }

    /**
     * A new contact started from the contact list, not for the form under a dialog.
     */
    private function forContacts(Request $request): bool
    {
        return self::FOR_CONTACTS === $request->query->getString('for');
    }

    /**
     * @return array<string, string>
     */
    private function kindOptions(): array
    {
        $kinds = [];
        foreach (PartyKind::cases() as $kind) {
            $kinds[$kind->value] = $this->translator->trans('party.kind.'.$kind->value);
        }

        return $kinds;
    }

    /**
     * @return array<string, string|list<string>>
     */
    private function defaults(): array
    {
        return [
            'kind' => PartyKind::Company->value,
            'displayName' => '',
            'registrationCountry' => 'CZ',
            'registrationNumber' => '',
            'vatId' => '',
            'email' => '',
            'phone' => '',
            'mobile' => '',
            'website' => '',
            'roles' => [PartyRole::Customer->value],
            'street' => '',
            'city' => '',
            'postalCode' => '',
            'countryCode' => 'CZ',
            'privateNote' => '',
        ];
    }

    /**
     * What a page that opens a new contact already knows, such as the name and numbers a received
     * document was read with: only these texts, each cut to its field; the registry lookup of the
     * form completes the rest, and the form checks everything as when it is typed.
     *
     * @return array<string, string>
     */
    private function prefilled(Request $request): array
    {
        $prefilled = [];
        foreach (self::PREFILL as $field => $length) {
            $value = $request->query->get($field);
            if (\is_string($value) && '' !== trim($value)) {
                $prefilled[$field] = mb_substr(trim($value), 0, $length);
            }
        }
        // A country the form offers, or none: the address and the registration keep their default.
        $country = $request->query->get('countryCode');
        $country = \is_string($country) ? strtoupper(trim($country)) : '';
        if (CountryOptions::supports($country) && \in_array($country, CountryOptions::CODES, true)) {
            $prefilled['countryCode'] = $country;
            $prefilled['registrationCountry'] = $country;
        }

        return $prefilled;
    }

    /**
     * @return array<string, string|list<string>>
     */
    private function valuesOf(Party $party): array
    {
        $billing = $this->details->billingAddress($party)?->address();
        $registration = $party->registrationNumber();

        return [
            'kind' => $party->kind()->value,
            'displayName' => $party->displayName(),
            'registrationCountry' => $registration->countryCode ?? 'CZ',
            'registrationNumber' => $registration->value ?? '',
            'vatId' => $party->vatId()?->toString() ?? '',
            'email' => $this->details->generalEmail($party)?->address() ?? '',
            'phone' => $party->phone(),
            'mobile' => $party->mobile(),
            'website' => $party->website(),
            'roles' => array_map(static fn (PartyRole $role): string => $role->value, $party->roles()),
            'street' => $billing->street ?? '',
            'city' => $billing->city ?? '',
            'postalCode' => $billing->postalCode ?? '',
            'countryCode' => $billing->countryCode ?? 'CZ',
            'privateNote' => $party->privateNote(),
        ];
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::FORM_TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
