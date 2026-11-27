<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Webhooks\Infrastructure\Column;

use Foresi\Shared\Domain\View\AttributeType;
use Foresi\Ui\Contract\Attribute;
use Foresi\Ui\Contract\DataSource;
use Foresi\Webhooks\Application\View\WebhookEndpointLists;
use Foresi\Webhooks\Domain\WebhookEndpoint;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The columns of the webhook endpoints, those the page always showed: the URL, the description,
 * the events it receives, whether it is active and when it was made.
 */
final readonly class WebhookEndpointColumns implements DataSource
{
    public const string LIST = WebhookEndpointLists::KEY;

    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function attributes(string $list): iterable
    {
        if (self::LIST !== $list) {
            return [];
        }
        $label = static fn (string $key): string => 'webhooks.column.'.$key;
        $events = static fn (WebhookEndpoint $endpoint): string => implode(', ', $endpoint->eventTypes()->patterns());
        $status = fn (WebhookEndpoint $endpoint): array => $endpoint->isActive()
            ? ['label' => $this->translator->trans('webhooks.state.active'), 'tone' => 'green']
            : ['label' => $this->translator->trans('webhooks.state.disabled'), 'tone' => 'red'];
        $created = fn (WebhookEndpoint $endpoint): string => $endpoint->createdAt()->format($this->translator->trans('format.datetime'));

        return [
            new Attribute('url', $label('url'), AttributeType::Text, static fn (WebhookEndpoint $endpoint): string => $endpoint->url()->value, defaultVisible: true, defaultPosition: 10, cell: static fn (WebhookEndpoint $endpoint): string => $endpoint->url()->value, locked: true, truncate: true),
            new Attribute('description', $label('description'), AttributeType::Text, static fn (WebhookEndpoint $endpoint): ?string => $endpoint->description(), defaultVisible: true, defaultPosition: 20, cell: static fn (WebhookEndpoint $endpoint): ?string => $endpoint->description(), phone: false, truncate: true),
            new Attribute('events', $label('events'), AttributeType::Text, $events, defaultVisible: true, defaultPosition: 30, cell: static fn (WebhookEndpoint $endpoint): array => ['badges' => array_map(static fn (string $pattern): array => ['label' => $pattern, 'tone' => 'gray'], $endpoint->eventTypes()->patterns())], phone: false),
            new Attribute('status', $label('status'), AttributeType::Text, fn (WebhookEndpoint $endpoint): string => $status($endpoint)['label'], defaultVisible: true, defaultPosition: 40, cell: fn (WebhookEndpoint $endpoint): array => ['badges' => [$status($endpoint)]]),
            new Attribute('created', $label('created'), AttributeType::Text, $created, defaultVisible: true, defaultPosition: 50, cell: $created, phone: false),
        ];
    }
}
