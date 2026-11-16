<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Application;

use App\Webhooks\Domain\EndpointUrl;

/**
 * Decides whether a URL a person typed may receive webhooks. People choose the
 * URL, so without this the server could be made to post to its own network or
 * a cloud metadata address. Sending checks the connected address again.
 */
interface WebhookUrlPolicy
{
    /**
     * @throws InvalidWebhookUrl with the reason it is refused
     */
    public function vet(string $url): EndpointUrl;
}
