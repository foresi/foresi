<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Infrastructure\Controller;

use Foresi\Audit\Application\AuditActor;
use Foresi\Party\Application\CreateParty;
use Foresi\Party\Application\DuplicateFinder;
use Foresi\Party\Application\DuplicateMatch;
use Foresi\Party\Application\PartyDetails;
use Foresi\Party\Application\PartyInput;
use Foresi\Party\Application\PartyQuery;
use Foresi\Party\Application\RegistryRecord;
use Foresi\Party\Application\TransactionalWork;
use Foresi\Party\Application\UpdateParty;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PartyRole;
use Foresi\Party\Infrastructure\Form\CountryOptions;
use Foresi\Party\Infrastructure\Form\PartySubmission;
use Foresi\Party\Infrastructure\Form\PartySubmissionReader;
use Foresi\Party\Infrastructure\Registry\RefreshRegistryChecksAfterResponse;
use Foresi\Party\Infrastructure\Registry\RegistryLookup;
use Foresi\Shared\Infrastructure\Http\SubmissionTokens;
use Foresi\Ui\Contract\Dialog\DialogRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Creating and editing contacts, and the registry lookup that fills the form.
 * A new contact can also come from a short form in a dialog: over the invoice
 * form, a new customer whose id the page under it picks; over the contact list
 * (?for=contacts), a new contact of any role, whose page opens once it is saved.
 * The same URL without the dialog is the whole form, a page of its own.
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
        private readonly RegistryLookup $lookup,
        private readonly AuditActor $actor,
        #[Autowire(service: 'limiter.party_lookup')]
        private readonly RateLimiterFactory $lookupLimiter,
        private readonly TranslatorInterface $translator,
        private readonly TransactionalWork $transaction,
    ) {
    }

    #[Route('/contacts/new', name: 'app_contact_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        if (!$request->isMethod('POST')) {
            return $this->form($request, null, $this->prefilled($request) + $this->defaults(), [], [], $this->tokens->issue(), Response::HTTP_OK);
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
            return $this->form($request, $party, $this->valuesOf($party), [], [], '', Response::HTTP_OK);
        }

        $this->checkToken($request);

        return $this->handle($request, $party, $this->reader->read($request, $party->kind()), '');
    }

    /**
     * Registry records for a registration number or the start of a name, with
     * the contact of this address book that already has the same number.
     */
    #[Route('/contacts/lookup', name: 'app_contact_lookup', methods: ['GET'])]
    public function lookup(Request $request): JsonResponse
    {
        $limit = $this->lookupLimiter->create((string) ($this->actor->userId() ?? $request->getClientIp()))->consume();
        if (!$limit->isAccepted()) {
            $retry = max(1, $limit->getRetryAfter()->getTimestamp() - time());

            return new JsonResponse(['error' => 'rate_limited'], Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => (string) $retry]);
        }

        $results = [];
        foreach ($this->lookup->find($request->query->getString('q')) as $record) {
            $results[] = $this->record($record);
        }

        return new JsonResponse(['results' => $results]);
    }

    private function handle(Request $request, ?Party $party, PartySubmission $submission, string $token): Response
    {
        $input = $submission->input;
        if (!$submission->valid() || !$input instanceof PartyInput) {
            return $this->form($request, $party, $submission->values, $submission->errors, [], $token, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $matches = $this->duplicates->find($input->registrationNumber, $input->vatId, true, $party?->id());
        if ([] !== $matches && !$request->request->getBoolean('confirm_duplicate')) {
            return $this->form($request, $party, $submission->values, [], $matches, $token, Response::HTTP_UNPROCESSABLE_ENTITY);
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
        } catch (\DomainException) {
            return $this->form($request, $party, $submission->values, ['form' => 'party.error.save_failed'], [], $token, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($changedVat && $input->vatId instanceof \Foresi\Party\Domain\VatId) {
            RefreshRegistryChecksAfterResponse::schedule($request, $id);
        }
        if (!$party instanceof Party && DialogRequest::is($request) && !$this->forContacts($request)) {
            // The page under the dialog takes the new contact: no message waits for another page.
            return new JsonResponse(['event' => 'contact:created', 'detail' => ['id' => $id], 'message' => $this->translator->trans('party.flash.created')]);
        }
        $this->addFlash('success', $this->translator->trans($party instanceof Party ? 'party.flash.updated' : 'party.flash.created'));
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
     */
    private function form(Request $request, ?Party $party, array $values, array $errors, array $matches, string $token, int $status): Response
    {
        $locale = $request->getLocale();
        $messages = array_map(fn (string $key): string => $this->translator->trans($key), $errors);

        // A new contact from a dialog is the short form; the page is the whole one.
        $template = !$party instanceof Party && DialogRequest::is($request) ? '@Party/quick.html.twig' : '@Party/form.html.twig';

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
            'kinds' => $this->kindOptions(),
            'roleOptions' => array_combine(array_map(static fn (PartyRole $role): string => $role->value, PartyRole::cases()), array_map(fn (PartyRole $role): string => $this->translator->trans('party.role.'.$role->value), PartyRole::cases())),
            'countries' => CountryOptions::options($locale),
            'action' => $party instanceof Party ? $this->generateUrl('app_contact_edit', ['id' => $party->id()]) : $this->generateUrl('app_contact_new', $forContacts ? ['for' => self::FOR_CONTACTS] : []),
            'cancel' => $party instanceof Party ? $this->generateUrl('app_contact_show', ['id' => $party->id()]) : $this->generateUrl('app_contacts'),
        ], new Response(status: $status));
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

    /**
     * @return array<string, mixed>
     */
    private function record(RegistryRecord $record): array
    {
        $existing = $this->duplicates->find($record->registrationNumber, $record->vatId)[0] ?? null;

        return [
            'registrationCountry' => $record->registrationNumber->countryCode,
            'registrationNumber' => $record->registrationNumber->value,
            'name' => $record->name,
            'vatId' => $record->vatId?->toString(),
            'street' => $record->seat->street ?? '',
            'city' => $record->seat->city ?? '',
            'postalCode' => $record->seat->postalCode ?? '',
            'countryCode' => $record->seat->countryCode ?? 'CZ',
            'existing' => null === $existing ? null : [
                'name' => $existing->displayName,
                'url' => $this->generateUrl('app_contact_show', ['id' => $existing->partyId]),
            ],
        ];
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::FORM_TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
