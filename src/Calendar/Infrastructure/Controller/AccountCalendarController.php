<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Calendar\Infrastructure\Controller;

use Foresi\Calendar\Application\CalendarRefused;
use Foresi\Calendar\Application\CreateFeedInput;
use Foresi\Calendar\Application\FeedReads;
use Foresi\Calendar\Application\FeedWrites;
use Foresi\Calendar\Application\RenameFeedInput;
use Foresi\Calendar\Application\RevokeFeedInput;
use Foresi\Calendar\Domain\CalendarFeed;
use Foresi\Calendar\Infrastructure\Api\FeedAddresses;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Shared\Contract\RefusalKind;
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
 * The person's calendar feeds in their account, next to their notification
 * settings: the list with name, created and last fetched, a new feed in a dialog
 * whose answer shows the address once (the way a new API token is shown), and
 * Rename and Revoke in each feed's menu. Patterns: Google Calendar's secret
 * address with its reset, Todoist's "Generate new feed" and GitLab's per-feed
 * token. The staff only; a guest is refused by the use cases.
 */
final class AccountCalendarController extends AbstractController
{
    public const string ROUTE = 'app_calendar_feeds';
    public const string NEW_ROUTE = 'app_calendar_feed_new';
    public const string RENAME_ROUTE = 'app_calendar_feed_rename';
    public const string REVOKE_ROUTE = 'app_calendar_feed_revoke';
    public const string TOKEN = 'calendar_feeds';

    public function __construct(
        private readonly FeedReads $reads,
        private readonly FeedWrites $writes,
        private readonly FeedAddresses $addresses,
        private readonly InstanceClock $clock,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/account/calendar', name: self::ROUTE, methods: ['GET'])]
    public function index(): Response
    {
        $feeds = $this->refused($this->reads->mine(...));

        return $this->render('@Calendar/feeds/index.html.twig', [
            'columns' => [
                ['key' => 'name', 'label' => $this->translator->trans('calendar.feeds.column.name'), 'truncate' => true],
                ['key' => 'created', 'label' => $this->translator->trans('calendar.feeds.column.created'), 'phone' => false],
                ['key' => 'lastFetched', 'label' => $this->translator->trans('calendar.feeds.column.last_fetched')],
            ],
            'rows' => array_map($this->row(...), $feeds),
        ]);
    }

    /**
     * The form in a dialog (the same URL is a page of its own) and, posted, the one answer that shows the address.
     */
    #[Route('/account/calendar/new', name: self::NEW_ROUTE, methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->refused($this->reads->mine(...));
        $name = '';
        $error = null;
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            $name = $request->request->getString('name');
            try {
                $issued = $this->writes->create(new CreateFeedInput($name));
                $response = $this->render('@Calendar/feeds/created.html.twig', [
                    'name' => $issued->feed->name(),
                    'address' => $this->addresses->address($issued->token),
                    'webcal' => $this->addresses->webcal($issued->token),
                ]);
                $response->headers->set('Cache-Control', 'no-store, private');

                return $response;
            } catch (CalendarRefused $refused) {
                $error = $this->formError($refused);
            }
        }

        return $this->form('new', $this->generateUrl(self::NEW_ROUTE), $name, $error);
    }

    #[Route('/account/calendar/{id}/rename', name: self::RENAME_ROUTE, requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function rename(string $id, Request $request): Response
    {
        $feed = $this->mine($id);
        $name = $feed->name();
        $error = null;
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            $name = $request->request->getString('name');
            try {
                $this->writes->rename(new RenameFeedInput($id, $name));
                $this->addFlash('success', $this->translator->trans('calendar.feeds.flash.renamed'));

                return $this->redirectToRoute(self::ROUTE);
            } catch (CalendarRefused $refused) {
                $error = $this->formError($refused);
            }
        }

        return $this->form('rename', $this->generateUrl(self::RENAME_ROUTE, ['id' => $id]), $name, $error);
    }

    #[Route('/account/calendar/{id}/revoke', name: self::REVOKE_ROUTE, requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function revoke(string $id, Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $feed = $this->refused(fn (): CalendarFeed => $this->writes->revoke(new RevokeFeedInput($id)));
        $this->addFlash('success', $this->translator->trans('calendar.feeds.flash.revoked', ['name' => $feed->name()]));

        return $this->redirectToRoute(self::ROUTE);
    }

    private function form(string $mode, string $action, string $name, ?string $error): Response
    {
        return $this->render('@Calendar/feeds/form.html.twig', [
            'mode' => $mode,
            'action' => $action,
            'name' => $name,
            'error' => $error,
        ], new Response(status: null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    private function mine(string $id): CalendarFeed
    {
        foreach ($this->refused($this->reads->mine(...)) as $feed) {
            if ($feed->id() === $id && !$feed->isRevoked()) {
                return $feed;
            }
        }

        throw $this->createNotFoundException('You have no calendar feed with this id.');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(CalendarFeed $feed): array
    {
        $zone = $this->clock->timezone();
        $format = $this->translator->trans('format.datetime');
        $fetched = $feed->lastFetchedAt();
        $created = $feed->createdAt()->setTimezone($zone)->format($format);

        return [
            'id' => $feed->id(),
            'label' => $feed->name(),
            'secondary' => $created,
            'cells' => [
                'name' => $feed->isRevoked() ? ['text' => $feed->name(), 'flag' => ['label' => $this->translator->trans('calendar.feeds.state.revoked'), 'tone' => 'red']] : $feed->name(),
                'created' => $created,
                'lastFetched' => $fetched instanceof \DateTimeImmutable ? $fetched->setTimezone($zone)->format($format) : $this->translator->trans('calendar.feeds.never'),
            ],
            'actions' => $feed->isRevoked() ? [] : [
                ['label' => $this->translator->trans('calendar.feeds.action.rename'), 'href' => $this->generateUrl(self::RENAME_ROUTE, ['id' => $feed->id()]), 'method' => 'GET', 'dialog' => true],
                ['label' => $this->translator->trans('calendar.feeds.action.revoke'), 'href' => $this->generateUrl(self::REVOKE_ROUTE, ['id' => $feed->id()]), 'method' => 'POST', 'confirm' => [
                    'title' => $this->translator->trans('calendar.feeds.confirm.revoke.title', ['name' => $feed->name()]),
                    'text' => $this->translator->trans('calendar.feeds.confirm.revoke.text'),
                    'accept' => $this->translator->trans('calendar.feeds.action.revoke'),
                    'tone' => 'danger',
                ]],
            ],
        ];
    }

    private function formError(CalendarRefused $refused): string
    {
        if (RefusalKind::Invalid !== $refused->kind()) {
            throw $this->httpError($refused);
        }

        return $this->translator->trans('calendar.feeds.refused.'.$refused->reason());
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
        } catch (CalendarRefused $refused) {
            throw $this->httpError($refused);
        }
    }

    private function httpError(CalendarRefused $refused): \Throwable
    {
        return RefusalKind::NotFound === $refused->kind()
            ? new NotFoundHttpException($refused->getMessage(), $refused)
            : new AccessDeniedHttpException($refused->getMessage(), $refused);
    }
}
