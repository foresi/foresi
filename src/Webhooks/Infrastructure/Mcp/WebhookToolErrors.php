<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Infrastructure\Mcp;

use App\Shared\Infrastructure\Mcp\ToolRefusal;
use App\Webhooks\Application\DeliveryNotRedeliverable;
use App\Webhooks\Application\InvalidWebhookEndpoint;
use App\Webhooks\Application\InvalidWebhookUrl;
use App\Webhooks\Application\UnknownWebhookDelivery;
use App\Webhooks\Application\UnknownWebhookEndpoint;
use App\Webhooks\Application\WebhookEndpointDisabled;
use App\Webhooks\Application\WebhookNotAllowed;
use App\Webhooks\Application\WebhookSecretsUnavailable;

/**
 * What the webhook use cases refuse, as the tools answer it: the same split as
 * WebhookErrors makes for REST. A value that cannot be used is an invalid
 * argument with its name, a module the person may not read forbidden, an
 * endpoint or delivery the organisation does not have not found, an attempt
 * that cannot be made now a conflict, and an installation without a key to
 * seal secrets unavailable.
 */
final class WebhookToolErrors
{
    /**
     * @template T
     *
     * @param \Closure(): T $action
     *
     * @return T
     *
     * @throws ToolRefusal
     */
    public static function refuse(\Closure $action): mixed
    {
        try {
            return $action();
        } catch (InvalidWebhookUrl $invalid) {
            throw ToolRefusal::invalidArgument(\sprintf('The argument "url" cannot be used: %s', $invalid->getMessage()));
        } catch (InvalidWebhookEndpoint $invalid) {
            throw ToolRefusal::invalidArgument(\sprintf('The argument "%s" cannot be used: %s', $invalid->parameter, $invalid->getMessage()));
        } catch (WebhookNotAllowed $denied) {
            throw ToolRefusal::forbidden($denied->getMessage());
        } catch (UnknownWebhookEndpoint) {
            throw ToolRefusal::notFound('The organisation has no webhook endpoint with this id, or you may not manage it. Find the id with foresi_list_webhook_endpoints.');
        } catch (UnknownWebhookDelivery) {
            throw ToolRefusal::notFound('The endpoint has no delivery with this id. Find the id with foresi_list_webhook_deliveries.');
        } catch (WebhookEndpointDisabled|DeliveryNotRedeliverable $conflict) {
            throw ToolRefusal::conflict($conflict->getMessage());
        } catch (WebhookSecretsUnavailable $unavailable) {
            throw ToolRefusal::unavailable($unavailable->getMessage());
        }
    }
}
