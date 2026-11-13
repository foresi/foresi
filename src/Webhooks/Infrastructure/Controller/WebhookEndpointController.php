<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Infrastructure\Controller;

use App\Webhooks\Application\DeliveryNotRedeliverable;
use App\Webhooks\Application\InvalidWebhookEndpoint;
use App\Webhooks\Application\InvalidWebhookUrl;
use App\Webhooks\Application\ManageWebhookEndpoints;
use App\Webhooks\Application\Query\AttemptRow;
use App\Webhooks\Application\Query\DeliveryRow;
use App\Webhooks\Application\Query\WebhookDeliveryLog;
use App\Webhooks\Application\Query\WebhookEndpointList;
use App\Webhooks\Application\SubscribableEvents;
use App\Webhooks\Application\UnknownWebhookDelivery;
use App\Webhooks\Application\UnknownWebhookEndpoint;
use App\Webhooks\Application\WebhookAccess;
use App\Webhooks\Application\WebhookEndpointDisabled;
use App\Webhooks\Application\WebhookNotAllowed;
use App\Webhooks\Application\WebhookSecretsUnavailable;
use App\Webhooks\Domain\WebhookEndpoint;
use App\Webhooks\Domain\WebhookSecret;
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
 * Settings › Webhooks: the endpoints of the organisation, each with its
 * deliveries and their attempts. New and Edit are dialogs, the other actions
 * sit in the page header of an endpoint and in the row menus. The page is there
 * for a person who may read the events of at least one module, and offers only
 * the events of such modules; who may change an endpoint is decided by the use
 * cases, and this controller turns their refusals into answers.
 *
 * A secret is in the answer that creates or rotates the endpoint, or reveals
 * its secret on purpose: never redirected to, stored in the session or cached.
 */
final class WebhookEndpointController extends AbstractController
{
    public const string ROUTE = 'app_webhooks';
    public const string NEW_ROUTE = 'app_webhook_new';
    public const string SHOW_ROUTE = 'app_webhook';
    public const string EDIT_ROUTE = 'app_webhook_edit';
    public const string ROTATE_ROUTE = 'app_webhook_rotate';
    public const string SECRET_ROUTE = 'app_webhook_secret';
    public const string DISABLE_ROUTE = 'app_webhook_disable';
    public const string ENABLE_ROUTE = 'app_webhook_enable';
    public const string PING_ROUTE = 'app_webhook_ping';
    public const string DELETE_ROUTE = 'app_webhook_delete';
    public const string DELIVERY_ROUTE = 'app_webhook_delivery';
    public const string REDELIVER_ROUTE = 'app_webhook_redeliver';
    public const string TOKEN = 'webhooks';
    /** How long the previous secret may keep signing after a rotation, as the dialog offers it. */
    public const array KEEP_PREVIOUS_HOURS = [0, 1, 24, 72, ManageWebhookEndpoints::MAX_KEEP_PREVIOUS_HOURS];

    public function __construct(
        private readonly ManageWebhookEndpoints $manage,
        private readonly WebhookEndpointList $endpoints,
        private readonly WebhookDeliveryLog $deliveries,
        private readonly WebhookAccess $access,
        private readonly SubscribableEvents $events,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/settings/webhooks', name: self::ROUTE, methods: ['GET'])]
    public function index(): Response
    {
        $this->denyUnlessAnyModule();
        $rows = [];
        $after = null;
        do {
            $slice = $this->endpoints->slice($after, WebhookEndpointList::MAX_LIMIT);
            foreach ($slice->endpoints as $endpoint) {
                $rows[] = $this->endpointRow($endpoint);
            }
            $after = $slice->lastId();
        } while ($slice->hasMore && null !== $after);

        return $this->render('webhooks/index.html.twig', [
            'columns' => [
                ['key' => 'url', 'label' => $this->translator->trans('webhooks.column.url'), 'truncate' => true],
                ['key' => 'description', 'label' => $this->translator->trans('webhooks.column.description'), 'truncate' => true, 'phone' => false],
                ['key' => 'events', 'label' => $this->translator->trans('webhooks.column.events'), 'phone' => false],
                ['key' => 'status', 'label' => $this->translator->trans('webhooks.column.status')],
                ['key' => 'created', 'label' => $this->translator->trans('webhooks.column.created'), 'phone' => false],
            ],
            'rows' => $rows,
        ]);
    }

    /**
     * The form in a dialog (the same URL is a page of its own) and, posted,
     * the one answer that shows the secret.
     */
    #[Route('/settings/webhooks/new', name: self::NEW_ROUTE, methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->denyUnlessAnyModule();
        $values = ['url' => '', 'description' => '', 'event_types' => [], 'version' => '1'];
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            $values = $this->values($request);
            try {
                $created = $this->manage->create($values['url'], '' === $values['description'] ? null : $values['description'], $values['event_types'], $this->version($values['version']));

                return $this->secret($created->secret, $created->endpoint, 'created');
            } catch (InvalidWebhookUrl|InvalidWebhookEndpoint|WebhookSecretsUnavailable $refused) {
                $errors = $this->errors($refused);
            } catch (WebhookNotAllowed $refused) {
                throw new AccessDeniedHttpException($refused->getMessage(), $refused);
            }
        }

        return $this->render('webhooks/new.html.twig', [
            'values' => $values,
            'errors' => $errors,
            'modules' => $this->eventOptions(),
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/settings/webhooks/{id}', name: self::SHOW_ROUTE, requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function show(string $id): Response
    {
        $this->denyUnlessAnyModule();
        $endpoint = $this->endpoint($id);
        $slice = $this->deliveries->slice($id, null, WebhookDeliveryLog::MAX_LIMIT);
        $previous = $endpoint->previousExpiresAt();

        return $this->render('webhooks/show.html.twig', [
            'endpoint' => $endpoint,
            'title' => $this->title($endpoint),
            'disabledReason' => $endpoint->disabledReason()?->value,
            'details' => array_filter([
                ['label' => $this->translator->trans('webhooks.column.url'), 'value' => $endpoint->url()->value],
                ['label' => $this->translator->trans('webhooks.column.description'), 'value' => $endpoint->description() ?? '—'],
                ['label' => $this->translator->trans('webhooks.column.events'), 'value' => implode(', ', $endpoint->eventTypes()->patterns())],
                ['label' => $this->translator->trans('webhooks.field.version'), 'value' => (string) $endpoint->version()],
                ['label' => $this->translator->trans('webhooks.column.status'), 'badge' => $this->status($endpoint)],
                ['label' => $this->translator->trans('webhooks.column.created'), 'value' => $this->dateTime($endpoint->createdAt())],
                $previous instanceof \DateTimeImmutable && $previous > $this->clock->now()
                    ? ['label' => $this->translator->trans('webhooks.detail.previous_secret'), 'value' => $this->dateTime($previous)]
                    : null,
            ]),
            'deliveryColumns' => [
                ['key' => 'event', 'label' => $this->translator->trans('webhooks.delivery.column.event'), 'truncate' => true],
                ['key' => 'status', 'label' => $this->translator->trans('webhooks.column.status')],
                ['key' => 'attempts', 'label' => $this->translator->trans('webhooks.delivery.column.attempts'), 'align' => 'right', 'phone' => false],
                ['key' => 'code', 'label' => $this->translator->trans('webhooks.delivery.column.code'), 'align' => 'right', 'phone' => false],
                ['key' => 'created', 'label' => $this->translator->trans('webhooks.column.created')],
                ['key' => 'next', 'label' => $this->translator->trans('webhooks.delivery.column.next'), 'phone' => false],
            ],
            'deliveryRows' => array_map(fn (DeliveryRow $row): array => $this->deliveryRow($endpoint, $row), $slice->rows),
            'moreDeliveries' => $slice->hasMore,
        ]);
    }

    #[Route('/settings/webhooks/{id}/edit', name: self::EDIT_ROUTE, requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request): Response
    {
        $this->denyUnlessAnyModule();
        $endpoint = $this->endpoint($id);
        $values = [
            'url' => $endpoint->url()->value,
            'description' => $endpoint->description() ?? '',
            'event_types' => $endpoint->eventTypes()->patterns(),
            'version' => (string) $endpoint->version(),
        ];
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            $values = $this->values($request);
            try {
                $this->manage->update($id, $values['url'], $values['description'], $values['event_types'], $this->version($values['version']));
                $this->addFlash('success', $this->translator->trans('webhooks.flash.saved'));

                return $this->redirectToRoute(self::SHOW_ROUTE, ['id' => $id]);
            } catch (InvalidWebhookUrl|InvalidWebhookEndpoint $refused) {
                $errors = $this->errors($refused);
            } catch (WebhookNotAllowed $refused) {
                throw new AccessDeniedHttpException($refused->getMessage(), $refused);
            } catch (UnknownWebhookEndpoint $refused) {
                throw new NotFoundHttpException($refused->getMessage(), $refused);
            }
        }

        return $this->render('webhooks/edit.html.twig', [
            'endpoint' => $endpoint,
            'title' => $this->title($endpoint),
            'values' => $values,
            'errors' => $errors,
            'modules' => $this->eventOptions(),
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /**
     * The choice of how long the current secret keeps signing, and, posted,
     * the one answer that shows the new secret.
     */
    #[Route('/settings/webhooks/{id}/rotate', name: self::ROTATE_ROUTE, requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function rotate(string $id, Request $request): Response
    {
        $this->denyUnlessAnyModule();
        $endpoint = $this->endpoint($id);
        $hours = (string) ManageWebhookEndpoints::DEFAULT_KEEP_PREVIOUS_HOURS;
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            $hours = $request->request->getString('keep_previous_hours');
            try {
                $secret = $this->guarded(fn (): WebhookSecret => $this->manage->rotate($id, \in_array($hours, array_map(strval(...), self::KEEP_PREVIOUS_HOURS), true) ? (int) $hours : -1));

                return $this->secret($secret, $this->endpoint($id), 'rotated');
            } catch (InvalidWebhookEndpoint|WebhookSecretsUnavailable $refused) {
                $errors = $this->errors($refused);
            }
        }

        return $this->render('webhooks/rotate.html.twig', [
            'endpoint' => $endpoint,
            'title' => $this->title($endpoint),
            'hours' => $hours,
            'errors' => $errors,
            'options' => array_combine(
                array_map(strval(...), self::KEEP_PREVIOUS_HOURS),
                array_map(fn (int $hours): string => $this->translator->trans('webhooks.rotate.keep', ['hours' => $hours]), self::KEEP_PREVIOUS_HOURS),
            ),
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /**
     * The secret the endpoint signs with now: the dialog asks first, and only
     * the posted answer holds it.
     */
    #[Route('/settings/webhooks/{id}/secret', name: self::SECRET_ROUTE, requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function reveal(string $id, Request $request): Response
    {
        $this->denyUnlessAnyModule();
        $endpoint = $this->endpoint($id);
        $error = null;
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            try {
                return $this->secret($this->guarded(fn (): WebhookSecret => $this->manage->reveal($id)), $endpoint, 'revealed');
            } catch (WebhookSecretsUnavailable) {
                $error = $this->translator->trans('webhooks.refused.secrets_unavailable');
            }
        }

        return $this->noStore($this->render('webhooks/secret.html.twig', [
            'endpoint' => $endpoint,
            'title' => $this->title($endpoint),
            'secret' => null,
            'mode' => 'reveal',
            'error' => $error,
        ], new Response(status: null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY)));
    }

    #[Route('/settings/webhooks/{id}/disable', name: self::DISABLE_ROUTE, requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function disable(string $id, Request $request): RedirectResponse
    {
        return $this->act($request, $id, fn (): WebhookEndpoint => $this->manage->disable($id), 'webhooks.flash.disabled');
    }

    #[Route('/settings/webhooks/{id}/enable', name: self::ENABLE_ROUTE, requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function enable(string $id, Request $request): RedirectResponse
    {
        return $this->act($request, $id, fn (): WebhookEndpoint => $this->manage->enable($id), 'webhooks.flash.enabled');
    }

    #[Route('/settings/webhooks/{id}/ping', name: self::PING_ROUTE, requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function ping(string $id, Request $request): RedirectResponse
    {
        return $this->act($request, $id, fn (): \App\Webhooks\Application\QueuedAttempt => $this->manage->ping($id), 'webhooks.flash.pinged');
    }

    #[Route('/settings/webhooks/{id}/delete', name: self::DELETE_ROUTE, requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function delete(string $id, Request $request): RedirectResponse
    {
        $this->act($request, $id, fn () => $this->manage->delete($id), 'webhooks.flash.deleted');

        return $this->redirectToRoute(self::ROUTE);
    }

    /**
     * The attempts of one delivery, in a dialog over the endpoint.
     */
    #[Route('/settings/webhooks/{id}/deliveries/{deliveryId}', name: self::DELIVERY_ROUTE, requirements: ['id' => Requirement::UUID, 'deliveryId' => Requirement::UUID], methods: ['GET'])]
    public function delivery(string $id, string $deliveryId): Response
    {
        $this->denyUnlessAnyModule();
        $endpoint = $this->endpoint($id);
        try {
            $detail = $this->guarded(fn (): \App\Webhooks\Application\Query\DeliveryDetail => $this->deliveries->delivery($id, $deliveryId));
        } catch (UnknownWebhookDelivery $unknown) {
            throw new NotFoundHttpException($unknown->getMessage(), $unknown);
        }

        return $this->render('webhooks/attempts.html.twig', [
            'endpoint' => $endpoint,
            'title' => $this->title($endpoint),
            'event' => $this->eventLabel($detail->delivery),
            'columns' => [
                ['key' => 'at', 'label' => $this->translator->trans('webhooks.attempt.column.at')],
                ['key' => 'trigger', 'label' => $this->translator->trans('webhooks.attempt.column.trigger'), 'phone' => false],
                ['key' => 'code', 'label' => $this->translator->trans('webhooks.delivery.column.code'), 'align' => 'right'],
                ['key' => 'duration', 'label' => $this->translator->trans('webhooks.attempt.column.duration'), 'align' => 'right', 'phone' => false],
                ['key' => 'error', 'label' => $this->translator->trans('webhooks.attempt.column.error'), 'truncate' => true],
            ],
            'rows' => array_map(fn (AttemptRow $attempt): array => [
                'id' => $attempt->id,
                'cells' => [
                    'at' => $this->dateTime($attempt->attemptedAt),
                    'trigger' => $this->translator->trans('webhooks.attempt.trigger.'.$attempt->trigger),
                    'code' => null === $attempt->statusCode ? '—' : (string) $attempt->statusCode,
                    'duration' => $this->translator->trans('webhooks.attempt.duration', ['ms' => $attempt->durationMs]),
                    'error' => $attempt->error ?? '—',
                ],
            ], $detail->attempts),
        ]);
    }

    #[Route('/settings/webhooks/{id}/deliveries/{deliveryId}/redeliver', name: self::REDELIVER_ROUTE, requirements: ['id' => Requirement::UUID, 'deliveryId' => Requirement::UUID], methods: ['POST'])]
    public function redeliver(string $id, string $deliveryId, Request $request): RedirectResponse
    {
        try {
            return $this->act($request, $id, fn (): \App\Webhooks\Application\QueuedAttempt => $this->manage->redeliver($id, $deliveryId), 'webhooks.flash.redelivered');
        } catch (UnknownWebhookDelivery $unknown) {
            throw new NotFoundHttpException($unknown->getMessage(), $unknown);
        }
    }

    /**
     * Runs one of the header and row actions and goes back to the endpoint
     * with what became of it. A refusal the person can act on is a message.
     *
     * @param \Closure(): mixed $action
     */
    private function act(Request $request, string $id, \Closure $action, string $flash): RedirectResponse
    {
        $this->denyUnlessAnyModule();
        $this->checkToken($request);
        try {
            $this->guarded($action);
            $this->addFlash('success', $this->translator->trans($flash));
        } catch (WebhookEndpointDisabled) {
            $this->addFlash('error', $this->translator->trans('webhooks.refused.disabled'));
        } catch (DeliveryNotRedeliverable) {
            $this->addFlash('error', $this->translator->trans('webhooks.refused.not_redeliverable'));
        } catch (WebhookSecretsUnavailable) {
            $this->addFlash('error', $this->translator->trans('webhooks.refused.secrets_unavailable'));
        }

        return $this->redirectToRoute(self::SHOW_ROUTE, ['id' => $id]);
    }

    /**
     * Turns the refusals every call can meet into answers.
     *
     * @template T
     *
     * @param \Closure(): T $call
     *
     * @return T
     */
    private function guarded(\Closure $call): mixed
    {
        try {
            return $call();
        } catch (UnknownWebhookEndpoint $unknown) {
            throw new NotFoundHttpException($unknown->getMessage(), $unknown);
        } catch (WebhookNotAllowed $refused) {
            throw new AccessDeniedHttpException($refused->getMessage(), $refused);
        }
    }

    private function endpoint(string $id): WebhookEndpoint
    {
        return $this->guarded(fn (): WebhookEndpoint => $this->endpoints->show($id));
    }

    /**
     * The page is there for a person who may read the events of a module.
     */
    private function denyUnlessAnyModule(): void
    {
        if ([] === $this->access->readableModules()) {
            throw new AccessDeniedHttpException('Webhooks are for a person who may read the events of a module.');
        }
    }

    /**
     * The events a person may subscribe to, by module: all of a module, or some.
     *
     * @return array<string, array<string, string>> module => pattern => label
     */
    private function eventOptions(): array
    {
        $modules = [];
        foreach ($this->access->readableModules() as $module) {
            $names = $this->events->names($module.'.*');
            if ([] === $names) {
                continue;
            }
            $modules[$module] = [$module.'.*' => $this->translator->trans('webhooks.field.all_events', ['module' => $module])] + array_combine($names, $names);
        }

        return $modules;
    }

    /**
     * @return array{url: string, description: string, event_types: list<string>, version: string}
     */
    private function values(Request $request): array
    {
        $types = array_values(array_filter($request->request->all('event_types'), static fn (mixed $type): bool => \is_string($type) && '' !== $type));

        return [
            'url' => trim($request->request->getString('url')),
            'description' => trim($request->request->getString('description')),
            'event_types' => $types,
            'version' => trim($request->request->getString('version')),
        ];
    }

    private function version(string $version): int
    {
        return ctype_digit($version) && \strlen($version) <= 6 ? (int) $version : 0;
    }

    /**
     * @return array<string, string> the field a refusal belongs to, or "form", and what to say there
     */
    private function errors(InvalidWebhookUrl|InvalidWebhookEndpoint|WebhookSecretsUnavailable $refused): array
    {
        return match (true) {
            $refused instanceof InvalidWebhookUrl => ['url' => $this->translator->trans('webhooks.refused.url.'.$refused->reason)],
            $refused instanceof InvalidWebhookEndpoint => [$refused->parameter => $this->translator->trans('webhooks.refused.'.$refused->parameter)],
            default => ['form' => $this->translator->trans('webhooks.refused.secrets_unavailable')],
        };
    }

    /**
     * The answer that hands a secret over, this once and not cached. A dialog
     * that created or rotated loads the page under it again on closing.
     */
    private function secret(WebhookSecret $secret, WebhookEndpoint $endpoint, string $mode): Response
    {
        $previous = $endpoint->previousExpiresAt();

        return $this->noStore($this->render('webhooks/secret.html.twig', [
            'endpoint' => $endpoint,
            'title' => $this->title($endpoint),
            'secret' => $secret->reveal(),
            'mode' => $mode,
            'previousUntil' => 'rotated' === $mode && $previous instanceof \DateTimeImmutable && $previous > $this->clock->now() ? $this->dateTime($previous) : null,
            'error' => null,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function endpointRow(WebhookEndpoint $endpoint): array
    {
        $id = $endpoint->id();
        $actions = [['label' => $this->translator->trans('webhooks.action.edit'), 'href' => $this->generateUrl(self::EDIT_ROUTE, ['id' => $id]), 'method' => 'GET', 'dialog' => true]];
        if ($endpoint->isActive()) {
            $actions[] = ['label' => $this->translator->trans('webhooks.action.ping'), 'href' => $this->generateUrl(self::PING_ROUTE, ['id' => $id]), 'method' => 'POST'];
            $actions[] = ['label' => $this->translator->trans('webhooks.action.disable'), 'href' => $this->generateUrl(self::DISABLE_ROUTE, ['id' => $id]), 'method' => 'POST'];
        } else {
            $actions[] = ['label' => $this->translator->trans('webhooks.action.enable'), 'href' => $this->generateUrl(self::ENABLE_ROUTE, ['id' => $id]), 'method' => 'POST'];
        }
        $actions[] = ['label' => $this->translator->trans('webhooks.action.delete'), 'href' => $this->generateUrl(self::DELETE_ROUTE, ['id' => $id]), 'method' => 'POST', 'confirm' => $this->deleteConfirm($endpoint)];

        return [
            'id' => $id,
            'href' => $this->generateUrl(self::SHOW_ROUTE, ['id' => $id]),
            'label' => $endpoint->url()->value,
            'secondary' => $endpoint->description(),
            'cells' => [
                'url' => $endpoint->url()->value,
                'description' => $endpoint->description() ?? '—',
                'events' => ['badges' => array_map(static fn (string $pattern): array => ['label' => $pattern, 'tone' => 'gray'], $endpoint->eventTypes()->patterns())],
                'status' => ['badges' => [$this->status($endpoint)]],
                'created' => $this->dateTime($endpoint->createdAt()),
            ],
            'actions' => $actions,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function deliveryRow(WebhookEndpoint $endpoint, DeliveryRow $row): array
    {
        $ids = ['id' => $endpoint->id(), 'deliveryId' => $row->id];
        $actions = [['label' => $this->translator->trans('webhooks.action.attempts'), 'href' => $this->generateUrl(self::DELIVERY_ROUTE, $ids), 'method' => 'GET', 'dialog' => true]];
        if ($endpoint->isActive() && !$row->isPending() && !$row->isPing()) {
            $actions[] = ['label' => $this->translator->trans('webhooks.action.redeliver'), 'href' => $this->generateUrl(self::REDELIVER_ROUTE, $ids), 'method' => 'POST'];
        }
        $next = $row->isPending() ? $row->nextAttemptAt : $row->finishedAt;

        return [
            'id' => $row->id,
            'label' => $this->eventLabel($row),
            'cells' => [
                'event' => $this->eventLabel($row),
                'status' => ['badges' => [[
                    'label' => $this->translator->trans('webhooks.delivery.status.'.$row->status),
                    'tone' => match ($row->status) {
                        DeliveryRow::SUCCEEDED => 'green',
                        DeliveryRow::FAILED => 'red',
                        default => 'amber',
                    },
                ]]],
                'attempts' => (string) $row->attempts,
                'code' => null === $row->lastStatusCode ? '—' : (string) $row->lastStatusCode,
                'created' => $this->dateTime($row->createdAt),
                'next' => $next instanceof \DateTimeImmutable ? $this->dateTime($next) : '—',
            ],
            'actions' => $actions,
        ];
    }

    private function eventLabel(DeliveryRow $row): string
    {
        return $row->isPing() ? $this->translator->trans('webhooks.delivery.ping') : $row->eventType;
    }

    /**
     * @return array{label: string, tone: string}
     */
    private function status(WebhookEndpoint $endpoint): array
    {
        return $endpoint->isActive()
            ? ['label' => $this->translator->trans('webhooks.state.active'), 'tone' => 'green']
            : ['label' => $this->translator->trans('webhooks.state.disabled'), 'tone' => 'red'];
    }

    /**
     * @return array<string, string>
     */
    private function deleteConfirm(WebhookEndpoint $endpoint): array
    {
        return [
            'title' => $this->translator->trans('webhooks.confirm.delete.title', ['host' => $endpoint->url()->host()]),
            'text' => $this->translator->trans('webhooks.confirm.delete.text'),
            'accept' => $this->translator->trans('webhooks.action.delete'),
            'tone' => 'danger',
        ];
    }

    private function title(WebhookEndpoint $endpoint): string
    {
        return $endpoint->description() ?? $endpoint->url()->host();
    }

    private function dateTime(\DateTimeImmutable $at): string
    {
        return $at->format($this->translator->trans('format.datetime'));
    }

    /** A secret is shown once: no browser, proxy or Turbo cache keeps the answer. */
    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
