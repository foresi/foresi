<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Application;

use App\Audit\Application\AuditActor;
use App\Webhooks\Domain\EventTypes;
use App\Webhooks\Domain\WebhookEndpoint;

/**
 * Who sees and manages an endpoint: a signed-in person who may read every module
 * it subscribes to, as the modules' access services decide for the events feed.
 * An endpoint is the organisation's, not its owner's, so any such person may
 * change it; the owner is whoever registered it, and the fan-out asks again for
 * the owner before each delivery.
 */
final readonly class WebhookAccess
{
    public function __construct(private SubscribableEvents $events, private AuditActor $actor)
    {
    }

    /**
     * @return string the id of the signed-in person
     *
     * @throws WebhookNotAllowed when nobody is signed in
     */
    public function person(): string
    {
        return $this->actor->userId() ?? throw new WebhookNotAllowed('Webhooks are managed by a signed-in person.');
    }

    /**
     * @return list<string> the modules the signed-in person may read the events of
     */
    public function readableModules(): array
    {
        return null === $this->actor->userId() ? [] : $this->events->readableModules();
    }

    /**
     * @throws InvalidWebhookEndpoint for a pattern that stands for no event of the catalogue
     * @throws WebhookNotAllowed      for a pattern of a module the signed-in person may not read
     */
    public function denyUnlessMaySubscribe(EventTypes $types): void
    {
        $this->person();
        $readable = $this->readableModules();
        foreach ($types->patterns() as $pattern) {
            if ([] === $this->events->names($pattern)) {
                throw new InvalidWebhookEndpoint('event_types', \sprintf('The event type "%s" does not exist. Use an event name or "module.*".', $pattern));
            }
            if (!\in_array(self::module($pattern), $readable, true)) {
                throw new WebhookNotAllowed(\sprintf('The events of "%s" are not yours to read.', self::module($pattern)));
            }
        }
    }

    /**
     * @throws WebhookNotAllowed
     */
    public function denyUnlessMayManage(WebhookEndpoint $endpoint): void
    {
        if (!self::covers($endpoint, $this->readableModules())) {
            throw new WebhookNotAllowed('This webhook endpoint subscribes to events you may not read.');
        }
    }

    /**
     * Whether the modules given cover everything the endpoint subscribes to, for
     * a list that asks for the readable modules once.
     *
     * @param list<string> $readable
     */
    public static function covers(WebhookEndpoint $endpoint, array $readable): bool
    {
        return array_all($endpoint->eventTypes()->patterns(), static fn (string $pattern): bool => \in_array(self::module($pattern), $readable, true));
    }

    private static function module(string $pattern): string
    {
        return explode('.', $pattern, 2)[0];
    }
}
