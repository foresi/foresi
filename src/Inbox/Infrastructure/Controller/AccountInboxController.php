<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Infrastructure\Controller;

use Foresi\Inbox\Application\InboxPurpose;
use Foresi\Inbox\Application\View\InboxAddressLists;
use Foresi\Inbox\Infrastructure\Column\InboxAddressColumns;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Mail\Contract\IssuedAddress;
use Foresi\Mail\Contract\OwnAddress;
use Foresi\Mail\Contract\OwnAddresses;
use Foresi\Shared\Application\View\ListSlot;
use Foresi\Shared\Contract\RefusalKind;
use Foresi\Shared\Contract\Refused;
use Foresi\Shared\Infrastructure\View\FilterSheet;
use Foresi\Ui\Contract\WorkingState;
use Foresi\Ui\View\ListTable;
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
 * The person's inbox addresses in their account, next to their calendar feeds
 * (the pattern of /account/calendar): the list by label with when it was made
 * and how many mails were dropped today, a new address in a dialog whose answer
 * shows the full address once, and Rename, Replace and Revoke in each row's
 * menu. They go through Mail's OwnAddresses contract, the same use cases as the API and MCP tools. The
 * page also tells how to forward mail from Gmail, Outlook and Apple Mail, with
 * the providers' own help pages. The addresses are a list with views
 * (docs/design/list-pages.md, rule 8). The staff only.
 */
final class AccountInboxController extends AbstractController
{
    public const string ROUTE = 'app_account_inbox';
    public const string NEW_ROUTE = 'app_account_inbox_new';
    public const string RENAME_ROUTE = 'app_account_inbox_rename';
    public const string REPLACE_ROUTE = 'app_account_inbox_replace';
    public const string REVOKE_ROUTE = 'app_account_inbox_revoke';
    public const string TOKEN = 'inbox_addresses';
    /** The providers' own pages on forwarding mail, cited as given. */
    public const array HELP = [
        'gmail' => 'https://support.google.com/mail/answer/10957',
        'outlook' => 'https://support.microsoft.com/en-us/office/turn-on-automatic-forwarding-in-outlook-7f2670a1-7fff-4475-8a3c-5822d63b0c8e',
        'apple' => 'https://support.apple.com/guide/icloud/forward-email-messages-mm6b1a490a/icloud',
    ];

    public function __construct(
        private readonly OwnAddresses $addresses,
        private readonly InstanceClock $clock,
        private readonly TranslatorInterface $translator,
        private readonly InboxAddressLists $lists,
        private readonly ListTable $listTable,
        private readonly FilterSheet $filterSheet,
    ) {
    }

    #[Route('/account/inbox', name: self::ROUTE, methods: ['GET'])]
    public function index(Request $request): Response
    {
        $address = $this->mine();
        $slot = ListSlot::first($request->query->all());
        $list = $this->lists->list();
        // The builder's rows (spec D11) become the list's working filter, in its address.
        $built = $this->listTable->built($slot, $list);
        if (null !== $built) {
            return $this->redirectToRoute(self::ROUTE, $built, Response::HTTP_SEE_OTHER);
        }
        $page = $this->listTable->of($slot, $list, InboxAddressColumns::LIST, self::ROUTE, 'inbox-addresses', $address instanceof OwnAddress ? [$address] : [], $this->row(...), $this->valueOf(...), static fn (OwnAddress $one): string => $one->label, $this->translator->trans('inbox.account.search'));

        return $this->render('@Inbox/account/index.html.twig', [
            'addresses' => $page->with($this->filterSheet->of($list, $page->shown, $page->href, pinned: $page->pinned, slot: $slot)),
            'canCreate' => !$address instanceof OwnAddress,
            'help' => self::HELP,
        ]);
    }

    /**
     * The form in a dialog (the same URL is a page of its own) and, posted, the one answer that shows the address.
     */
    #[Route('/account/inbox/new', name: self::NEW_ROUTE, methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->mine();
        $label = '';
        $error = null;
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            $label = $request->request->getString('label');
            try {
                return $this->issued($this->addresses->create(InboxPurpose::KEY, $label), $label);
            } catch (Refused $refused) {
                $error = $this->formError($refused);
            }
        }

        return $this->form('new', $this->generateUrl(self::NEW_ROUTE), $label, $error);
    }

    #[Route('/account/inbox/{id}/rename', name: self::RENAME_ROUTE, requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function rename(string $id, Request $request): Response
    {
        $address = $this->active($id);
        $label = $address->label;
        $error = null;
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            $label = $request->request->getString('label');
            try {
                $this->addresses->rename($id, $label);
                $this->addFlash('success', $this->translator->trans('inbox.account.flash.renamed'));

                return $this->redirectToRoute(self::ROUTE);
            } catch (Refused $refused) {
                $error = $this->formError($refused);
            }
        }

        return $this->form('rename', $this->generateUrl(self::RENAME_ROUTE, ['id' => $id]), $label, $error);
    }

    /**
     * Asks first (the old address stops working), then answers with the new address, shown once.
     */
    #[Route('/account/inbox/{id}/replace', name: self::REPLACE_ROUTE, requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function replace(string $id, Request $request): Response
    {
        $address = $this->active($id);
        $error = null;
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            try {
                return $this->issued($this->addresses->replace($id), $address->label);
            } catch (Refused $refused) {
                $error = $this->formError($refused);
            }
        }

        return $this->render('@Inbox/account/replace.html.twig', [
            'id' => $id,
            'label' => $address->label,
            'error' => $error,
        ], new Response(status: null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/account/inbox/{id}/revoke', name: self::REVOKE_ROUTE, requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function revoke(string $id, Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $address = $this->active($id);
        $this->refused(fn () => $this->addresses->revoke($id));
        $this->addFlash('success', $this->translator->trans('inbox.account.flash.revoked', ['label' => $address->label]));

        return $this->redirectToRoute(self::ROUTE);
    }

    /**
     * The one answer that shows the full address: never a redirect, never cached.
     */
    private function issued(IssuedAddress $issued, string $label): Response
    {
        $response = $this->render('@Inbox/account/created.html.twig', [
            'label' => $label,
            'address' => $issued->address,
            'help' => self::HELP,
        ]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private function form(string $mode, string $action, string $label, ?string $error): Response
    {
        return $this->render('@Inbox/account/form.html.twig', [
            'mode' => $mode,
            'action' => $action,
            'label' => $label,
            'error' => $error,
        ], new Response(status: null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /**
     * @return OwnAddress|null the person's inbox address that is not revoked, if any
     */
    private function mine(): ?OwnAddress
    {
        return $this->refused(fn (): ?OwnAddress => $this->addresses->mine(InboxPurpose::KEY));
    }

    private function active(string $id): OwnAddress
    {
        $address = $this->mine();
        if (!$address instanceof OwnAddress || $address->id !== $id) {
            throw new NotFoundHttpException('You have no inbox address with this id.');
        }

        return $address;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(OwnAddress $address, WorkingState $working): array
    {
        return [
            'id' => $address->id,
            'label' => $address->label,
            'secondary' => $address->createdAt->setTimezone($this->clock->timezone())->format($this->translator->trans('format.datetime')),
            'cells' => $working->display->cells($address),
            'actions' => [
                ['label' => $this->translator->trans('inbox.account.action.rename'), 'href' => $this->generateUrl(self::RENAME_ROUTE, ['id' => $address->id]), 'method' => 'GET', 'dialog' => true],
                ['label' => $this->translator->trans('inbox.account.action.replace'), 'href' => $this->generateUrl(self::REPLACE_ROUTE, ['id' => $address->id]), 'method' => 'GET', 'dialog' => true],
                ['label' => $this->translator->trans('inbox.account.action.revoke'), 'href' => $this->generateUrl(self::REVOKE_ROUTE, ['id' => $address->id]), 'method' => 'POST', 'confirm' => [
                    'title' => $this->translator->trans('inbox.account.confirm.revoke.title', ['label' => $address->label]),
                    'text' => $this->translator->trans('inbox.account.confirm.revoke.text'),
                    'accept' => $this->translator->trans('inbox.account.action.revoke'),
                    'tone' => 'danger',
                ]],
            ],
        ];
    }

    /**
     * The value of a field of the list on an address, as RowConditions compares it.
     */
    private function valueOf(OwnAddress $address, string $field): mixed
    {
        return match ($field) {
            InboxAddressLists::LABEL => $address->label,
            InboxAddressLists::DROPPED => $address->droppedToday,
            default => null,
        };
    }

    private function formError(Refused $refused): string
    {
        if (!\in_array($refused->kind(), [RefusalKind::Invalid, RefusalKind::Conflict, RefusalKind::Unavailable], true)) {
            throw $this->httpError($refused);
        }
        $key = 'inbox.account.refused.'.$refused->reason();
        $text = $this->translator->trans($key);

        return $text === $key ? $refused->getMessage() : $text;
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }

    /**
     * @template T
     *
     * @param callable(): T $action
     *
     * @return T
     */
    private function refused(callable $action): mixed
    {
        try {
            return $action();
        } catch (Refused $refused) {
            throw $this->httpError($refused);
        }
    }

    private function httpError(Refused $refused): \Throwable
    {
        return RefusalKind::NotFound === $refused->kind()
            ? new NotFoundHttpException($refused->getMessage(), $refused)
            : new AccessDeniedHttpException($refused->getMessage(), $refused);
    }
}
