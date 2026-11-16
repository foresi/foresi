<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Application\Query;

use App\Shared\Domain\Id;
use App\Webhooks\Application\UnknownWebhookEndpoint;
use App\Webhooks\Application\WebhookAccess;
use App\Webhooks\Application\WebhookEndpoints;
use App\Webhooks\Application\WebhookNotAllowed;
use App\Webhooks\Domain\WebhookEndpoint;

/**
 * The endpoints of the organisation for the API, MCP and the screen alike:
 * those the caller may manage, read by cursor, and one of them. Endpoints of a
 * module the caller may not read are left out of the list, so a slice can be
 * shorter than the limit and still have more after it.
 */
final readonly class WebhookEndpointList
{
    public const int DEFAULT_LIMIT = 25;
    public const int MAX_LIMIT = 100;
    private const int BATCH = 100;

    public function __construct(private WebhookEndpoints $endpoints, private WebhookAccess $access)
    {
    }

    /**
     * The newest endpoints first, those after the cursor. A limit is kept
     * between 1 and MAX_LIMIT.
     *
     * @param string|null $afterId the id of the last endpoint of the slice before; null for the first slice
     *
     * @throws WebhookNotAllowed when nobody is signed in
     */
    public function slice(?string $afterId = null, int $limit = self::DEFAULT_LIMIT): EndpointSlice
    {
        $this->access->person();
        if (null !== $afterId && !Id::isValid($afterId)) {
            return new EndpointSlice([], false);
        }
        $limit = max(1, min($limit, self::MAX_LIMIT));
        $readable = $this->access->readableModules();

        $visible = [];
        $cursor = $afterId;
        do {
            $batch = $this->endpoints->before($cursor, self::BATCH);
            foreach ($batch as $endpoint) {
                if (!WebhookAccess::covers($endpoint, $readable)) {
                    continue;
                }
                if (\count($visible) === $limit) {
                    return new EndpointSlice($visible, true);
                }
                $visible[] = $endpoint;
            }
            $cursor = [] === $batch ? $cursor : $batch[\count($batch) - 1]->id();
        } while (self::BATCH === \count($batch));

        return new EndpointSlice($visible, false);
    }

    /**
     * @throws UnknownWebhookEndpoint when the organisation has no such endpoint
     * @throws WebhookNotAllowed      when it subscribes to events the caller may not read
     */
    public function show(string $id): WebhookEndpoint
    {
        $this->access->person();
        $endpoint = Id::isValid($id) ? $this->endpoints->find($id) : null;
        if (!$endpoint instanceof WebhookEndpoint) {
            throw new UnknownWebhookEndpoint();
        }
        $this->access->denyUnlessMayManage($endpoint);

        return $endpoint;
    }
}
