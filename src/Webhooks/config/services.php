<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\Tests\Support\Mail\ExampleHostResolver;
use Foresi\Webhooks\Application\Query\DeliveryRecords;
use Foresi\Webhooks\Application\SubscribableEvents;
use Foresi\Webhooks\Application\WebhookEndpoints;
use Foresi\Webhooks\Application\WebhookQueue;
use Foresi\Webhooks\Application\WebhookSecrets;
use Foresi\Webhooks\Application\WebhookUrlPolicy;
use Foresi\Webhooks\Application\WebhookWork;
use Foresi\Webhooks\Infrastructure\Crypto\SodiumWebhookSecrets;
use Foresi\Webhooks\Infrastructure\Dbal\DbalDeliveryRecords;
use Foresi\Webhooks\Infrastructure\Delivery\MessengerWebhookQueue;
use Foresi\Webhooks\Infrastructure\Doctrine\DoctrineWebhookEndpoints;
use Foresi\Webhooks\Infrastructure\Doctrine\DoctrineWebhookWork;
use Foresi\Webhooks\Infrastructure\Events\CatalogueSubscribableEvents;
use Foresi\Webhooks\Infrastructure\Http\DnsWebhookUrlPolicy;
use Foresi\Webhooks\Infrastructure\Http\WebhookHttpClientFactory;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function Symfony\Component\DependencyInjection\Loader\Configurator\closure;
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/*
 * The services of the Webhooks module, loaded by Foresi\Webhooks\WebhooksBundle.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();
    // Integration code is registered by the kernel, only when its module is installed (ADR 0033).
    $services->load('Foresi\\Webhooks\\', '../')
        ->exclude(['../config/', '../Integration/', '../WebhooksBundle.php']);

    // A webhook endpoint may not be in a private, loopback or link-local network, as
    // people type the URL. Local development against a receiver on this machine sets this.
    $container->parameters()->set('app.webhooks.allow_private_hosts', false);

    $services->alias(WebhookSecrets::class, SodiumWebhookSecrets::class);
    $services->alias(WebhookUrlPolicy::class, DnsWebhookUrlPolicy::class);
    $services->alias(WebhookEndpoints::class, DoctrineWebhookEndpoints::class);
    $services->alias(WebhookWork::class, DoctrineWebhookWork::class);
    $services->alias(WebhookQueue::class, MessengerWebhookQueue::class);
    $services->alias(SubscribableEvents::class, CatalogueSubscribableEvents::class);
    $services->alias(DeliveryRecords::class, DbalDeliveryRecords::class);
    // Redirects are never followed and, unless this installation posts to its own network, private addresses are refused.
    $services->set('webhooks.http_client', HttpClientInterface::class)
        ->factory(WebhookHttpClientFactory::create(...))
        ->args([param('app.webhooks.allow_private_hosts')]);

    if (\in_array($container->env(), ['test', 'e2e'], true)) {
        // No test asks a real DNS server for a webhook host.
        $services->set(DnsWebhookUrlPolicy::class)
            ->arg('$resolver', closure(service(ExampleHostResolver::class)));
        $services->set(ExampleHostResolver::class);
    }
};
