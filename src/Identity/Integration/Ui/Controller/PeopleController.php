<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Integration\Ui\Controller;

use Foresi\Audit\Application\AuditActor;
use Foresi\Identity\Application\InvitationIssued;
use Foresi\Identity\Application\InvitationRefused;
use Foresi\Identity\Application\Invitations;
use Foresi\Identity\Application\InvitePeople;
use Foresi\Identity\Application\ManagePeople;
use Foresi\Identity\Application\OwnerCheck;
use Foresi\Identity\Application\People;
use Foresi\Identity\Application\PeopleRefused;
use Foresi\Identity\Application\Person;
use Foresi\Identity\Application\UserRepository;
use Foresi\Identity\Contract\PersonContactPicker;
use Foresi\Identity\Contract\StaffCheck;
use Foresi\Identity\Domain\Invitation;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Party\Contract\ContactPages;
use Foresi\Party\Domain\Party;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Infrastructure\Http\TypedAmount;
use Foresi\Ui\Contract\Opens;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The people of the organisation. The staff see who belongs to it; the owner
 * invites people, changes their role, contact and hourly rate, lets them in or
 * not and resets their two-factor sign-in, and keeps the pending invitations.
 * Only the owner sees a rate. The contact field and column come with the address
 * book's picker; without it they are not shown and a stored contact stays (ADR 0049).
 */
final class PeopleController extends AbstractController
{
    public const string ROUTE = 'app_people';
    public const string INVITATIONS_ROUTE = 'app_people_invitations';
    public const string TOKEN = 'people';
    private const int MAX_NAME = 255;
    private const string LINK_KEY = 'identity.invitation_link.';

    public function __construct(
        private readonly People $people,
        private readonly Invitations $invitations,
        private readonly InvitePeople $inviting,
        private readonly ManagePeople $managing,
        private readonly UserRepository $users,
        private readonly OwnerCheck $owner,
        private readonly StaffCheck $staff,
        private readonly AuditActor $actor,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
        private readonly ?PersonContactPicker $contactPicker = null,
        private readonly ?ContactPages $contactPages = null,
    ) {
    }

    #[Route('/settings/people', name: self::ROUTE, methods: ['GET'])]
    public function index(): Response
    {
        if (!$this->staff->isStaff()) {
            throw new AccessDeniedHttpException('Only the staff see the people of the organisation.');
        }
        $owner = $this->owner->isOwner();

        return $this->render('@Identity/people/index.html.twig', [
            'tab' => 'people',
            'owner' => $owner,
            'columns' => $this->peopleColumns(),
            'rows' => array_map(fn (Person $person): array => $this->personRow($person, $owner), $this->people->all()),
            'pendingCount' => $owner ? \count($this->invitations->pending($this->clock->now())) : 0,
        ]);
    }

    #[Route('/settings/people/invitations', name: self::INVITATIONS_ROUTE, methods: ['GET'])]
    public function invitations(): Response
    {
        $this->denyUnlessOwner();
        $pending = $this->invitations->pending($this->clock->now());

        return $this->render('@Identity/people/index.html.twig', [
            'tab' => 'invitations',
            'owner' => true,
            'columns' => $this->invitationColumns(),
            'rows' => array_map($this->invitationRow(...), $pending),
            'pendingCount' => \count($pending),
        ]);
    }

    #[Route('/settings/people/invite', name: 'app_people_invite', methods: ['GET', 'POST'])]
    public function invite(Request $request): Response
    {
        $this->denyUnlessOwner();
        $values = ['email' => '', 'name' => '', 'role' => Role::Member->value, 'locale' => $this->inviterLocale()];
        if ($this->contactPicker instanceof PersonContactPicker) {
            $values += ['contactId' => '', 'contactQuery' => ''];
        }
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            foreach (array_keys($values) as $field) {
                $values[$field] = trim($request->request->getString($field));
            }
            $values['name'] = mb_substr($values['name'], 0, self::MAX_NAME);
            $role = Role::tryFrom($values['role']);
            $locale = \in_array($values['locale'], User::LOCALES, true) ? $values['locale'] : $this->inviterLocale();
            if (null === $role || Role::Owner === $role) {
                $errors['role'] = $this->translator->trans('people.refused.role');
            } else {
                try {
                    $contactId = $values['contactId'] ?? '';
                    $issued = $this->inviting->invite($values['email'], $values['name'], $role, '' === $contactId ? null : $contactId, $locale);

                    return $this->issued($issued, $request);
                } catch (InvitationRefused $refused) {
                    $errors[self::FIELD_OF[$refused->reason] ?? 'form'] = $this->translator->trans('people.refused.'.$refused->reason);
                }
            }
        }

        return $this->render('@Identity/people/invite.html.twig', [
            'values' => $values,
            'errors' => $errors,
            'roles' => [Role::Member->value => $this->translator->trans('role.member'), Role::Guest->value => $this->translator->trans('role.guest')],
            'locales' => $this->locales(),
            'contactSearch' => $this->contactPicker?->searchUrl(),
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/settings/people/{id}/edit', name: 'app_people_edit', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request): Response
    {
        $this->denyUnlessOwner();
        $membership = $this->people->membership($id) ?? throw new NotFoundHttpException();
        $party = $membership->party();
        $rate = $membership->hourlyRate();
        $values = [
            'role' => $membership->role()->value,
            'rate' => TypedAmount::field($rate, $request->getLocale()),
            'rateCurrency' => ($rate?->currency() ?? Currency::CZK)->value,
        ];
        $picksContact = $this->contactPicker instanceof PersonContactPicker;
        if ($picksContact) {
            $values += [
                'contactId' => $party?->id() ?? '',
                'contactQuery' => $party instanceof Party ? $party->displayName() : '',
            ];
        }
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            // A submission without the contact field leaves the contact as it is; only an empty field unlinks it.
            $linksContact = $picksContact && $request->request->has('contactId');
            foreach (array_keys($values) as $field) {
                if ($request->request->has($field) || !\in_array($field, ['contactId', 'contactQuery'], true)) {
                    $values[$field] = trim($request->request->getString($field));
                }
            }
            $role = Role::tryFrom($values['role']);
            $currency = Currency::tryFrom($values['rateCurrency']);
            $rate = $currency instanceof Currency ? TypedAmount::read($values['rate'], $currency, $request->getLocale()) : false;
            if (false === $rate) {
                $errors['rate'] = $this->translator->trans('people.refused.rate');
            }
            if (!$role instanceof Role) {
                $errors['role'] = $this->translator->trans('people.refused.role');
            }
            if ([] === $errors && $role instanceof Role) {
                try {
                    $contactId = $values['contactId'] ?? '';
                    $this->managing->change($id, $role, '' === $contactId ? null : $contactId, $rate, $linksContact);
                    $this->addFlash('success', $this->translator->trans('people.flash.changed', ['name' => $membership->user()->displayName()]));

                    return $this->redirectToRoute(self::ROUTE);
                } catch (PeopleRefused $refused) {
                    $errors['contact' === $refused->reason ? 'contact' : 'role'] = $this->translator->trans('people.refused.'.$refused->reason);
                }
            }
        }

        return $this->render('@Identity/people/edit.html.twig', [
            'person' => $membership->user(),
            'values' => $values,
            'errors' => $errors,
            'roles' => array_combine(array_map(static fn (Role $role): string => $role->value, Role::cases()), array_map(fn (Role $role): string => $this->translator->trans('role.'.$role->value), Role::cases())),
            'currencies' => array_combine(array_map(static fn (Currency $currency): string => $currency->value, Currency::cases()), array_map(static fn (Currency $currency): string => $currency->value, Currency::cases())),
            'action' => $this->generateUrl('app_people_edit', ['id' => $id]),
            'contactSearch' => $this->contactPicker?->searchUrl(),
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/settings/people/{id}/deactivate', name: 'app_people_deactivate', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function deactivate(string $id, Request $request): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        return $this->manage($request, fn () => $this->managing->deactivate($id), 'people.flash.deactivated');
    }

    #[Route('/settings/people/{id}/reactivate', name: 'app_people_reactivate', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function reactivate(string $id, Request $request): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        return $this->manage($request, fn () => $this->managing->reactivate($id), 'people.flash.reactivated');
    }

    #[Route('/settings/people/{id}/reset-two-factor', name: 'app_people_reset_two_factor', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function resetTwoFactor(string $id, Request $request): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        return $this->manage($request, fn () => $this->managing->resetTwoFactor($id), 'people.flash.two_factor_reset');
    }

    #[Route('/settings/people/invitations/{id}/resend', name: 'app_people_invitation_resend', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function resend(string $id, Request $request): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $this->denyUnlessOwner();
        $this->checkToken($request);
        try {
            return $this->issued($this->inviting->resend($id), $request);
        } catch (InvitationRefused $refused) {
            $this->addFlash('error', $this->translator->trans('people.refused.'.$refused->reason));

            return $this->redirectToRoute(self::INVITATIONS_ROUTE);
        }
    }

    #[Route('/settings/people/invitations/{id}/revoke', name: 'app_people_invitation_revoke', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function revoke(string $id, Request $request): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $this->denyUnlessOwner();
        $this->checkToken($request);
        try {
            $this->inviting->revoke($id);
            $this->addFlash('success', $this->translator->trans('people.flash.revoked'));
        } catch (InvitationRefused $refused) {
            $this->addFlash('error', $this->translator->trans('people.refused.'.$refused->reason));
        }

        return $this->redirectToRoute(self::INVITATIONS_ROUTE);
    }

    /** Which field of the invitation form a refusal points at. */
    private const array FIELD_OF = ['email' => 'email', 'member' => 'email', 'name' => 'name', 'role' => 'role', 'contact' => 'contact'];

    /**
     * A mailed invitation goes back to the list; without system mail the owner
     * gets the link to pass on, on a page of its own after a redirect, as Turbo
     * expects of a form. The link waits in the session for that page, once.
     */
    private function issued(InvitationIssued $issued, Request $request): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        if ($issued->mailed) {
            $this->addFlash('success', $this->translator->trans('people.flash.invited', ['email' => $issued->invitation->email()]));

            return $this->redirectToRoute(self::INVITATIONS_ROUTE);
        }
        $request->getSession()->set(self::LINK_KEY.$issued->invitation->id(), $issued->link);

        return $this->redirectToRoute('app_people_invitation_link', ['id' => $issued->invitation->id()]);
    }

    #[Route('/settings/people/invitations/{id}/link', name: 'app_people_invitation_link', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function link(string $id, Request $request): Response
    {
        $this->denyUnlessOwner();
        $link = $request->getSession()->remove(self::LINK_KEY.$id);
        $invitation = $this->invitations->find($id);
        if (!\is_string($link) || !$invitation instanceof Invitation) {
            return $this->redirectToRoute(self::INVITATIONS_ROUTE);
        }

        return $this->render('@Identity/people/link.html.twig', [
            'invitation' => $invitation,
            'link' => $link,
            'expires' => $this->date($invitation->expiresAt()),
        ]);
    }

    /**
     * @param callable(): void $change
     */
    private function manage(Request $request, callable $change, string $done): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $this->denyUnlessOwner();
        $this->checkToken($request);
        try {
            $change();
            $this->addFlash('success', $this->translator->trans($done));
        } catch (PeopleRefused $refused) {
            $this->addFlash('error', $this->translator->trans('people.refused.'.$refused->reason));
        }

        return $this->redirectToRoute(self::ROUTE);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function peopleColumns(): array
    {
        return [
            ['key' => 'name', 'label' => $this->translator->trans('people.column.name'), 'truncate' => true],
            ['key' => 'email', 'label' => $this->translator->trans('people.column.email'), 'truncate' => true, 'phone' => false],
            ['key' => 'role', 'label' => $this->translator->trans('people.column.role')],
            ...$this->contactColumn(),
            ['key' => 'twoFactor', 'label' => $this->translator->trans('people.column.two_factor'), 'phone' => false],
            ['key' => 'status', 'label' => $this->translator->trans('people.column.status'), 'phone' => false],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function personRow(Person $person, bool $owner): array
    {
        $actions = [];
        if ($owner) {
            $actions[] = ['label' => $this->translator->trans('people.action.edit'), 'href' => $this->generateUrl('app_people_edit', ['id' => $person->membershipId]), 'icon' => 'pencil', 'dialog' => true];
            if ($person->twoFactor) {
                $actions[] = ['label' => $this->translator->trans('people.action.reset_two_factor'), 'href' => $this->generateUrl('app_people_reset_two_factor', ['id' => $person->membershipId]), 'method' => 'POST', 'confirm' => [
                    'title' => $this->translator->trans('people.confirm.reset_two_factor.title', ['name' => $person->name]),
                    'text' => $this->translator->trans('people.confirm.reset_two_factor.text'),
                    'accept' => $this->translator->trans('people.action.reset_two_factor'),
                    'tone' => 'danger',
                ]];
            }
            if ($person->userId !== $this->actor->userId()) {
                $actions[] = $person->active
                    ? ['label' => $this->translator->trans('people.action.deactivate'), 'href' => $this->generateUrl('app_people_deactivate', ['id' => $person->membershipId]), 'method' => 'POST', 'confirm' => [
                        'title' => $this->translator->trans('people.confirm.deactivate.title', ['name' => $person->name]),
                        'text' => $this->translator->trans('people.confirm.deactivate.text'),
                        'accept' => $this->translator->trans('people.action.deactivate'),
                        'tone' => 'danger',
                    ]]
                    : ['label' => $this->translator->trans('people.action.reactivate'), 'href' => $this->generateUrl('app_people_reactivate', ['id' => $person->membershipId]), 'method' => 'POST'];
            }
        }

        return [
            'id' => $person->membershipId,
            // The owner opens a person, a small record, in a modal over the list (docs/design/overlays.md).
            'href' => $owner ? $this->generateUrl('app_people_edit', ['id' => $person->membershipId]) : null,
            'opens' => Opens::DIALOG,
            'label' => $person->name,
            'secondary' => $person->email,
            'cells' => [
                'name' => ['avatar' => $person->userId, 'user' => $person->userId, 'text' => $person->name],
                'email' => $person->email,
                'role' => ['text' => $this->translator->trans('role.'.$person->role->value), 'tone' => Role::Owner === $person->role ? 'green' : 'gray'],
                'contact' => $this->contactCell($person->contactId, $person->contactName ?? ''),
                'twoFactor' => $person->twoFactor ? ['text' => $this->translator->trans('people.two_factor.on'), 'tone' => 'green'] : ['text' => $this->translator->trans('people.two_factor.off'), 'tone' => 'gray'],
                'status' => $person->active ? ['text' => $this->translator->trans('people.status.active'), 'tone' => 'green'] : ['text' => $this->translator->trans('people.status.deactivated'), 'tone' => 'amber'],
            ],
            'actions' => $actions,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function invitationColumns(): array
    {
        return [
            ['key' => 'name', 'label' => $this->translator->trans('people.column.name'), 'truncate' => true],
            ['key' => 'email', 'label' => $this->translator->trans('people.column.email'), 'truncate' => true, 'phone' => false],
            ['key' => 'role', 'label' => $this->translator->trans('people.column.role')],
            ...$this->contactColumn(),
            ['key' => 'expires', 'label' => $this->translator->trans('people.column.expires'), 'align' => 'right'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function invitationRow(Invitation $invitation): array
    {
        $party = $invitation->party();

        return [
            'id' => $invitation->id(),
            'label' => $invitation->displayName(),
            'secondary' => $invitation->email(),
            'cells' => [
                'name' => $invitation->displayName(),
                'email' => $invitation->email(),
                'role' => ['text' => $this->translator->trans('role.'.$invitation->role()->value), 'tone' => 'gray'],
                'contact' => $party instanceof Party ? $this->contactCell($party->id(), $party->displayName()) : null,
                'expires' => $this->date($invitation->expiresAt()),
            ],
            'actions' => [
                ['label' => $this->translator->trans('people.action.resend'), 'href' => $this->generateUrl('app_people_invitation_resend', ['id' => $invitation->id()]), 'method' => 'POST', 'icon' => 'send'],
                ['label' => $this->translator->trans('people.action.revoke'), 'href' => $this->generateUrl('app_people_invitation_revoke', ['id' => $invitation->id()]), 'method' => 'POST', 'confirm' => [
                    'title' => $this->translator->trans('people.confirm.revoke.title', ['email' => $invitation->email()]),
                    'text' => $this->translator->trans('people.confirm.revoke.text'),
                    'accept' => $this->translator->trans('people.action.revoke'),
                    'tone' => 'danger',
                ]],
            ],
        ];
    }

    /**
     * The contact column, shown only with the address book's picker.
     *
     * @return list<array<string, mixed>>
     */
    private function contactColumn(): array
    {
        return $this->contactPicker instanceof PersonContactPicker
            ? [['key' => 'contact', 'label' => $this->translator->trans('people.column.contact'), 'truncate' => true, 'phone' => false]]
            : [];
    }

    /**
     * The contact a person represents, linked to its page when the address book has one.
     *
     * @return array<string, mixed>|string|null
     */
    private function contactCell(?string $id, string $name): array|string|null
    {
        if (null === $id) {
            return null;
        }
        $href = $this->contactPages?->urlFor($id);

        return null === $href ? $name : ['links' => [['text' => $name, 'href' => $href]]];
    }

    /**
     * @return array<string, string>
     */
    private function locales(): array
    {
        return array_combine(User::LOCALES, array_map(fn (string $locale): string => $this->translator->trans('locale.'.$locale), User::LOCALES));
    }

    private function inviterLocale(): string
    {
        $id = $this->actor->userId();
        $user = null === $id ? null : $this->users->findById($id);

        return $user instanceof User ? $user->locale() : 'cs';
    }

    private function date(\DateTimeImmutable $date): string
    {
        return $date->format($this->translator->trans('format.date'));
    }

    private function denyUnlessOwner(): void
    {
        if (!$this->owner->isOwner()) {
            throw new AccessDeniedHttpException('Only the owner manages the people of the organisation.');
        }
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
