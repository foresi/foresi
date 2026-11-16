<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Webhooks\Infrastructure\Http;

use App\Webhooks\Infrastructure\Http\WebhookHttpClientFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * The client webhooks are sent with refuses private addresses unless the
 * installation allows them, and the container wires that client.
 */
final class WebhookHttpClientFactoryTest extends KernelTestCase
{
    public function testProductionClientRefusesAPrivateAddress(): void
    {
        $client = WebhookHttpClientFactory::create(false);

        self::assertInstanceOf(NoPrivateNetworkHttpClient::class, $client);
        $this->expectException(TransportExceptionInterface::class);
        $this->expectExceptionMessageIsOrContains('is blocked');
        $client->request('POST', 'https://127.0.0.1/receive')->getStatusCode();
    }

    public function testAllowingPrivateHostsDropsTheGuard(): void
    {
        self::assertNotInstanceOf(NoPrivateNetworkHttpClient::class, WebhookHttpClientFactory::create(true));
    }

    public function testContainerWiresTheGuardedClient(): void
    {
        self::bootKernel();

        self::assertInstanceOf(NoPrivateNetworkHttpClient::class, self::getContainer()->get('webhooks.http_client'));
    }
}
