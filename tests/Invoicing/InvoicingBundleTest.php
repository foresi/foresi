<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing;

use Foresi\Invoicing\Application\DeliverInvoiceSend;
use Foresi\Invoicing\Application\IssueRecurringTemplate;
use Foresi\Invoicing\Infrastructure\Messenger\WaitForLeaseMiddleware;
use Foresi\Invoicing\InvoicingBundle;
use Foresi\Tests\Shared\Support\PrependsModuleBundles;
use PHPUnit\Framework\TestCase;

/**
 * The bundle of Invoicing prepends the mapping of its contract, its Messenger
 * middleware and the routing of its messages.
 */
final class InvoicingBundleTest extends TestCase
{
    use PrependsModuleBundles;

    public function testInvoicingAddsItsMiddlewareBeforeItsRouting(): void
    {
        $builder = $this->builder('prod');
        new InvoicingBundle()->prependExtension($this->configurator($builder, 'prod'), $builder);

        self::assertSame(['InvoicingContract', 'Invoicing'], $this->mappings($builder));
        // The frozen party of a document is published in the contract and stored as an embeddable.
        self::assertSame(['orm' => ['mappings' => ['InvoicingContract' => [
            'type' => 'attribute',
            'is_bundle' => false,
            'dir' => $this->projectDir().'/src/Invoicing/Contract',
            'prefix' => 'Foresi\\Invoicing\\Contract',
            'alias' => 'InvoicingContract',
        ]]]], $builder->getExtensionConfig('doctrine')[0]);
        self::assertSame([
            ['messenger' => ['buses' => ['messenger.bus.default' => ['middleware' => [WaitForLeaseMiddleware::class]]]]],
            ['messenger' => ['routing' => [IssueRecurringTemplate::class => 'async', DeliverInvoiceSend::class => 'mail']]],
        ], $builder->getExtensionConfig('framework'));
    }
}
