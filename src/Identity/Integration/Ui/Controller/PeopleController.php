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
use Foresi\Identity\Application\View\PeopleLists;
use Foresi\Identity\Contract\PersonContactPicker;
use Foresi\Identity\Contract\StaffCheck;
use Foresi\Identity\Domain\Invitation;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Identity\Integration\Ui\Column\PeopleColumns;
use Foresi\Identity\Integration\Ui\Column\PeopleRow;
use Foresi\Party\Domain\Party;
use Foresi\Shared\Application\View\ListSlot;
use Foresi\Shared\Application\View\ListTables;
use Foresi\Shared\Application\View\ListView;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Domain\Currencies;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Infrastructure\Http\TypedAmount;
use Foresi\Shared\Infrastructure\View\FilterSheet;
use Foresi\Ui\Contract\Opens;
use Foresi\Ui\Contract\WorkingState;
use Psr\Clock\ClockInterface;
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
 * The people of the organisation. The staff see who belongs to it; the owner
 * invites people, changes their role, contact and hourly rate, lets them in or
 * not and resets their two-factor sign-in, and keeps the pending invitations.
 * Only the owner sees a rate. The contact field and column come with the address
 * book's picker; without it they are not shown and a stored contact stays (ADR 0049).
 * The people and the invitations are one list with views (docs/design/list-pages.md,
 * rule 8), the invitations its Invitations view.
 */
final class PeopleController extends AbstractController
{
    public const string ROUTE = 'app_people';
    public const string INVITATIONS_ROUTE = 'app_people_invitations';
    public const string TOKEN = 'people';
    private const int MAX_NAME = 255;
    private const string LINK_KEY = 'identity.invitation_link.';

    /**
     * @param ListTables<WorkingState> $listTable
     */
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
        private readonly PeopleLists $lists,
        private readonly ListTables $listTable,
        private readonly FilterSheet $filterSheet,
        private readonly ?PersonContactPicker $contactPicker = null,
    ) {
    }

    #[Route('/settings/people', name: self::ROUTE, methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->staff->isStaff()) {
            throw new AccessDeniedHttpException('Only the staff see the people of the organisation.');
        }
        $owner = $this->owner->isOwner();
        $slot = ListSlot::first($request->query->all());
        $list = $this->lists->list();
        // The builder's rows (spec D11) become the list's working filter, in its address.
        $built = $this->listTable->built($slot, $list);
        if (null !== $built) {
            return $this->redirectToRoute(self::ROUTE, $built, Response::HTTP_SEE_OTHER);
        }
        $records = array_map(PeopleRow::person(...), $this->people->all());
        if ($owner) {
            $records = [...$records, ...array_map(PeopleRow::invitation(...), $this->invitations->pending($this->clock->now()))];
        }
        $invitations = static fn (ViewFilter $shown): bool => [] !== $shown->on(PeopleLists::INVITED);
        $page = $this->listTable->of($slot, $list, PeopleColumns::LIST, self::ROUTE, 'people', $records,
            fn (PeopleRow $row, WorkingState $working): array => $row->isInvitation() ? $this->invitationRow($row, $working) : $this->personRow($row, $owner, $working),
            $this->valueOf(...), static fn (PeopleRow $row): string => $row->name().' '.$row->email(), $this->translator->trans('people.search'),
            PeopleLists::INVITED, static fn (ViewFilter $shown): array => ['invitations' => $invitations($shown)],
            // The invitations are the owner's (spec: only the owner gets them).
            static fn (ListView $view): bool => $owner || PeopleLists::INVITATIONS !== $view->key);

        return $this->render('@Identity/people/index.html.twig', [
            'owner' => $owner,
            'invitations' => $invitations($page->shown),
            'people' => $page->with($this->filterSheet->of($list, $page->shown, $page->href, pinned: $page->pinned, slot: $slot)),
        ]);
    }

    /**
     * The old address of the pending invitations, which are the list's Invitations view now.
     */
    #[Route('/settings/people/invitations', name: self::INVITATIONS_ROUTE, methods: ['GET'])]
    public function invitations(): RedirectResponse
    {
        $this->denyUnlessOwner();

        return $this->redirectToRoute(self::ROUTE, ['view' => PeopleLists::INVITATIONS]);
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
            'rateCurrency' => ($rate?->currency() ?? Currency::of('CZK'))->code(),
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
            'currencies' => Currencies::choices(),
            'action' => $this->generateUrl('app_people_edit', ['id' => $id]),
            'contactSearch' => $this->contactPicker?->searchUrl(),
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/settings/people/{id}/deactivate', name: 'app_people_deactivate', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function deactivate(string $id, Request $request): RedirectResponse
    {
        return $this->manage($request, fn () => $this->managing->deactivate($id), 'people.flash.deactivated');
    }

    #[Route('/settings/people/{id}/reactivate', name: 'app_people_reactivate', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function reactivate(string $id, Request $request): RedirectResponse
    {
        return $this->manage($request, fn () => $this->managing->reactivate($id), 'people.flash.reactivated');
    }

    #[Route('/settings/people/{id}/reset-two-factor', name: 'app_people_reset_two_factor', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function resetTwoFactor(string $id, Request $request): RedirectResponse
    {
        return $this->manage($request, fn () => $this->managing->resetTwoFactor($id), 'people.flash.two_factor_reset');
    }

    #[Route('/settings/people/invitations/{id}/resend', name: 'app_people_invitation_resend', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function resend(string $id, Request $request): RedirectResponse
    {
        $this->denyUnlessOwner();
        $this->checkToken($request);
        try {
            return $this->issued($this->inviting->resend($id), $request);
        } catch (InvitationRefused $refused) {
            $this->addFlash('error', $this->translator->trans('people.refused.'.$refused->reason));

            return $this->redirectToRoute(self::ROUTE, ['view' => PeopleLists::INVITATIONS]);
        }
    }

    #[Route('/settings/people/invitations/{id}/revoke', name: 'app_people_invitation_revoke', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function revoke(string $id, Request $request): RedirectResponse
    {
        $this->denyUnlessOwner();
        $this->checkToken($request);
        try {
            $this->inviting->revoke($id);
            $this->addFlash('success', $this->translator->trans('people.flash.revoked'));
        } catch (InvitationRefused $refused) {
            $this->addFlash('error', $this->translator->trans('people.refused.'.$refused->reason));
        }

        return $this->redirectToRoute(self::ROUTE, ['view' => PeopleLists::INVITATIONS]);
    }

    /**
     * The value of a field of the list on a person or an invitation, as RowConditions compares it.
     */
    private function valueOf(PeopleRow $row, string $field): mixed
    {
        return match ($field) {
            PeopleLists::NAME => $row->name(),
            PeopleLists::EMAIL => $row->email(),
            PeopleLists::ROLE => $row->role()->value,
            PeopleLists::INVITED => $row->isInvitation(),
            default => null,
        };
    }

    /** Which field of the invitation form a refusal points at. */
    private const array FIELD_OF = ['email' => 'email', 'member' => 'email', 'name' => 'name', 'role' => 'role', 'contact' => 'contact'];

    /**
     * A mailed invitation goes back to the list; without system mail the owner
     * gets the link to pass on, on a page of its own after a redirect, as Turbo
     * expects of a form. The link waits in the session for that page, once.
     */
    private function issued(InvitationIssued $issued, Request $request): RedirectResponse
    {
        if ($issued->mailed) {
            $this->addFlash('success', $this->translator->trans('people.flash.invited', ['email' => $issued->invitation->email()]));

            return $this->redirectToRoute(self::ROUTE, ['view' => PeopleLists::INVITATIONS]);
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
            return $this->redirectToRoute(self::ROUTE, ['view' => PeopleLists::INVITATIONS]);
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
    private function manage(Request $request, callable $change, string $done): RedirectResponse
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
     * @return array<string, mixed>
     */
    private function personRow(PeopleRow $row, bool $owner, WorkingState $working): array
    {
        $person = $row->person ?? throw new \LogicException('A row of a person.');
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
            'cells' => $working->display->cells($row),
            'actions' => $actions,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function invitationRow(PeopleRow $row, WorkingState $working): array
    {
        $invitation = $row->invitation ?? throw new \LogicException('A row of an invitation.');

        return [
            'id' => $invitation->id(),
            'label' => $invitation->displayName(),
            'secondary' => $invitation->email(),
            'cells' => $working->display->cells($row),
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
