<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing;

use Foresi\Invoicing\Application\DeliverInvoiceSend;
use Foresi\Invoicing\Application\IssueRecurringTemplate;
use Foresi\Invoicing\Infrastructure\Messenger\WaitForLeaseMiddleware;
use Foresi\Shared\Infrastructure\Module\ModuleBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * Issued invoices, credit notes and recurring templates, their payments and
 * their delivery by e-mail.
 */
final class InvoicingBundle extends ModuleBundle
{
    protected function messageRouting(): array
    {
        return [
            // A recurring template saved or resumed after the day's run, issued at once.
            IssueRecurringTemplate::class => 'async',
            // Sending documents by e-mail, on its own queue.
            DeliverInvoiceSend::class => 'mail',
        ];
    }

    protected function prepend(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->prependExtensionConfig('framework', ['messenger' => ['buses' => ['messenger.bus.default' => ['middleware' => [
            // A delivery waiting for another worker's lease is put back, not retried.
            WaitForLeaseMiddleware::class,
        ]]]]]);
    }
}
