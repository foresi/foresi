<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Webhooks;

use Foresi\Shared\Infrastructure\Module\ModuleBundle;
use Foresi\Webhooks\Application\SendWebhook;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * Outbound webhooks: endpoints, their secrets and the deliveries of events.
 *
 * The module brings its own Messenger transport, webhooks, so the queue exists
 * only where the module is installed and a worker consumes it only there
 * (app:worker:receivers).
 */
final class WebhooksBundle extends ModuleBundle
{
    public const string TRANSPORT = 'webhooks';

    protected function messageRouting(): array
    {
        return [
            SendWebhook::class => self::TRANSPORT,
        ];
    }

    protected function prepend(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->prependExtensionConfig('framework', ['messenger' => ['transports' => [self::TRANSPORT => $this->transport($container->env())]]]);
    }

    /**
     * One message per attempt, apart so a slow receiver holds up only this
     * transport. The delivery row keeps the schedule and the next attempt is
     * dispatched with a delay, so Messenger never retries. An attempt ends
     * within 15 s, well inside the redeliver timeout. The tests keep it in
     * memory; the end-to-end environment has no worker and sends inline.
     *
     * @return array<string, mixed>
     */
    private function transport(?string $environment): array
    {
        return match ($environment) {
            'test' => ['dsn' => 'in-memory://'],
            'e2e' => ['dsn' => 'sync://'],
            default => [
                'dsn' => '%env(MESSENGER_TRANSPORT_DSN)%',
                'options' => ['queue_name' => self::TRANSPORT, 'redeliver_timeout' => 600],
                'retry_strategy' => ['max_retries' => 0],
            ],
        };
    }
}
