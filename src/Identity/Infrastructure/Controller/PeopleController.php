<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Controller;

use App\Audit\Application\AuditActor;
use App\Identity\Application\InvitationIssued;
use App\Identity\Application\InvitationRefused;
use App\Identity\Application\Invitations;
use App\Identity\Application\InvitePeople;
use App\Identity\Application\ManagePeople;
use App\Identity\Application\OwnerCheck;
use App\Identity\Application\People;
use App\Identity\Application\PeopleRefused;
use App\Identity\Application\Person;
use App\Identity\Application\StaffCheck;
use App\Identity\Application\UserRepository;
use App\Identity\Domain\Invitation;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Party\Application\PartyQuery;
use App\Party\Application\PartyRow;
use App\Party\Domain\Party;
use Psr\Clock\ClockInterface;
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
 * The people of the organisation. The staff see who belongs to it; the owner
 * invites people, changes their role and contact, lets them in or not and resets
 * their two-factor sign-in, and keeps the pending invitations.
 */
final class PeopleController extends AbstractController
{
    public const string ROUTE = 'app_people';
    public const string INVITATIONS_ROUTE = 'app_people_invitations';
    public const string TOKEN = 'people';
    private const int MAX_QUERY = 100;
    private const int MAX_NAME = 255;
    private const string LINK_KEY = 'identity.invitation_link.';

    public function __construct(
        private readonly People $people,
        private readonly Invitations $invitations,
        private readonly InvitePeople $inviting,
        private readonly ManagePeople $managing,
        private readonly PartyQuery $parties,
        private readonly UserRepository $users,
        private readonly OwnerCheck $owner,
        private readonly StaffCheck $staff,
        private readonly AuditActor $actor,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/settings/people', name: self::ROUTE, methods: ['GET'])]
    public function index(): Response
    {
        if (!$this->staff->isStaff()) {
            throw new AccessDeniedHttpException('Only the staff see the people of the organisation.');
        }
        $owner = $this->owner->isOwner();

        return $this->render('identity/people/index.html.twig', [
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

        return $this->render('identity/people/index.html.twig', [
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
        $values = ['email' => '', 'name' => '', 'role' => Role::Member->value, 'contactId' => '', 'contactQuery' => '', 'locale' => $this->inviterLocale()];
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
                    $issued = $this->inviting->invite($values['email'], $values['name'], $role, '' === $values['contactId'] ? null : $values['contactId'], $locale);

                    return $this->issued($issued, $request);
                } catch (InvitationRefused $refused) {
                    $errors[self::FIELD_OF[$refused->reason] ?? 'form'] = $this->translator->trans('people.refused.'.$refused->reason);
                }
            }
        }

        return $this->render('identity/people/invite.html.twig', [
            'values' => $values,
            'errors' => $errors,
            'roles' => [Role::Member->value => $this->translator->trans('role.member'), Role::Guest->value => $this->translator->trans('role.guest')],
            'locales' => $this->locales(),
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/settings/people/contacts', name: 'app_people_contacts', methods: ['GET'])]
    public function contacts(Request $request): JsonResponse
    {
        $this->denyUnlessOwner();
        $page = $this->parties->search(mb_substr($request->query->getString('q'), 0, self::MAX_QUERY), null, false, 'name', 1);

        return $this->json(['results' => array_map(fn (PartyRow $row): array => [
            'id' => $row->id,
            'label' => $row->displayName,
            'hint' => $row->email ?? $row->registrationNumber ?? $this->translator->trans('party.kind.'.$row->kind->value),
        ], $page->rows)]);
    }

    #[Route('/settings/people/{id}/edit', name: 'app_people_edit', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request): Response
    {
        $this->denyUnlessOwner();
        $membership = $this->people->membership($id) ?? throw new NotFoundHttpException();
        $party = $membership->party();
        $values = ['role' => $membership->role()->value, 'contactId' => $party?->id() ?? '', 'contactQuery' => $party instanceof Party ? $party->displayName() : ''];
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            foreach (array_keys($values) as $field) {
                $values[$field] = trim($request->request->getString($field));
            }
            $role = Role::tryFrom($values['role']);
            if (!$role instanceof Role) {
                $errors['role'] = $this->translator->trans('people.refused.role');
            } else {
                try {
                    $this->managing->change($id, $role, '' === $values['contactId'] ? null : $values['contactId']);
                    $this->addFlash('success', $this->translator->trans('people.flash.changed', ['name' => $membership->user()->displayName()]));

                    return $this->redirectToRoute(self::ROUTE);
                } catch (PeopleRefused $refused) {
                    $errors['contact' === $refused->reason ? 'contact' : 'role'] = $this->translator->trans('people.refused.'.$refused->reason);
                }
            }
        }

        return $this->render('identity/people/edit.html.twig', [
            'person' => $membership->user(),
            'values' => $values,
            'errors' => $errors,
            'roles' => array_combine(array_map(static fn (Role $role): string => $role->value, Role::cases()), array_map(fn (Role $role): string => $this->translator->trans('role.'.$role->value), Role::cases())),
            'action' => $this->generateUrl('app_people_edit', ['id' => $id]),
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

        return $this->render('identity/people/link.html.twig', [
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
            ['key' => 'contact', 'label' => $this->translator->trans('people.column.contact'), 'truncate' => true, 'phone' => false],
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
            $actions[] = ['label' => $this->translator->trans('people.action.edit'), 'href' => $this->generateUrl('app_people_edit', ['id' => $person->membershipId]), 'icon' => 'pencil'];
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
            'label' => $person->name,
            'secondary' => $person->email,
            'cells' => [
                'name' => ['avatar' => $person->userId, 'text' => $person->name],
                'email' => $person->email,
                'role' => ['text' => $this->translator->trans('role.'.$person->role->value), 'tone' => Role::Owner === $person->role ? 'green' : 'gray'],
                'contact' => null === $person->contactId ? null : ['links' => [['text' => $person->contactName ?? '', 'href' => $this->generateUrl('app_contact_show', ['id' => $person->contactId])]]],
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
            ['key' => 'contact', 'label' => $this->translator->trans('people.column.contact'), 'truncate' => true, 'phone' => false],
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
                'contact' => $party instanceof Party ? ['links' => [['text' => $party->displayName(), 'href' => $this->generateUrl('app_contact_show', ['id' => $party->id()])]]] : null,
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
