<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Webhooks\Infrastructure\Http;

use App\Webhooks\Application\InvalidWebhookUrl;
use App\Webhooks\Infrastructure\Http\DnsWebhookUrlPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DnsWebhookUrlPolicyTest extends TestCase
{
    /** @var array<string, list<string>> */
    private const array NAMES = [
        'hooks.example.com' => ['93.184.215.14', '2606:2800:21f:cb07:6820:80da:af6b:8b2c'],
        'inside.example.com' => ['192.168.1.1'],
        'mixed.example.com' => ['93.184.215.14', '10.0.0.7'],
        'loop6.example.com' => ['::1'],
        'nothing.example.com' => [],
        'localhost' => ['127.0.0.1', '::1'],
    ];

    #[DataProvider('refused')]
    public function testARefusedUrlSaysWhy(string $url, string $reason): void
    {
        try {
            $this->policy()->vet($url);
            self::fail('The URL was accepted.');
        } catch (InvalidWebhookUrl $e) {
            self::assertSame($reason, $e->reason);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refused(): iterable
    {
        yield 'http' => ['http://hooks.example.com/x', InvalidWebhookUrl::SCHEME];
        yield 'http with no host' => ['http://', InvalidWebhookUrl::SCHEME];
        yield 'no scheme' => ['hooks.example.com/x', InvalidWebhookUrl::SCHEME];
        yield 'no host' => ['https:///x', InvalidWebhookUrl::HOST];
        yield 'not a host name' => ['https://hooks_example.com/x', InvalidWebhookUrl::HOST];
        yield 'user info' => ['https://user:pw@example.com', InvalidWebhookUrl::USERINFO];
        yield 'too long' => ['https://hooks.example.com/'.str_repeat('a', 2023), InvalidWebhookUrl::LENGTH];
        yield 'IPv4 loopback' => ['https://127.0.0.1', InvalidWebhookUrl::PRIVATE_ADDRESS];
        yield 'IPv6 loopback' => ['https://[::1]', InvalidWebhookUrl::PRIVATE_ADDRESS];
        yield 'cloud metadata' => ['https://169.254.169.254', InvalidWebhookUrl::PRIVATE_ADDRESS];
        yield 'private' => ['https://10.0.0.1', InvalidWebhookUrl::PRIVATE_ADDRESS];
        yield 'IPv4-mapped loopback' => ['https://[::ffff:127.0.0.1]/x', InvalidWebhookUrl::PRIVATE_ADDRESS];
        yield 'a name resolving to a private address' => ['https://inside.example.com/x', InvalidWebhookUrl::PRIVATE_ADDRESS];
        yield 'a name with one private address among public ones' => ['https://mixed.example.com/x', InvalidWebhookUrl::PRIVATE_ADDRESS];
        yield 'a name resolving to IPv6 loopback' => ['https://loop6.example.com/x', InvalidWebhookUrl::PRIVATE_ADDRESS];
        yield 'localhost' => ['https://localhost/x', InvalidWebhookUrl::PRIVATE_ADDRESS];
        yield 'a localhost name' => ['https://hooks.localhost/x', InvalidWebhookUrl::PRIVATE_ADDRESS];
        yield 'a name that does not resolve' => ['https://nothing.example.com/x', InvalidWebhookUrl::UNRESOLVABLE];
    }

    public function testAPublicHttpsUrlIsAccepted(): void
    {
        self::assertSame('https://hooks.example.com/x', $this->policy()->vet('https://hooks.example.com/x')->value);
        self::assertSame('https://Hooks.Example.com:8443/x?a=1', $this->policy()->vet('https://Hooks.Example.com:8443/x?a=1')->value);
        self::assertSame('https://93.184.215.14/x', $this->policy()->vet('https://93.184.215.14/x')->value);
    }

    public function testTheLongestUrlIsAccepted(): void
    {
        $url = 'https://hooks.example.com/'.str_repeat('a', 2022);

        self::assertSame(2048, \strlen($url));
        self::assertSame($url, $this->policy()->vet($url)->value);
    }

    public function testPrivateHostsAreAcceptedOnlyWhenTheInstallationAllowsThem(): void
    {
        $policy = $this->policy(true);

        self::assertSame('https://localhost/x', $policy->vet('https://localhost/x')->value);
        self::assertSame('https://inside.example.com/x', $policy->vet('https://inside.example.com/x')->value);
        self::assertSame('https://[::1]/x', $policy->vet('https://[::1]/x')->value);
    }

    public function testTheMessageDoesNotEchoTheUrl(): void
    {
        try {
            $this->policy()->vet('https://user:secret-password@example.com');
            self::fail('The URL was accepted.');
        } catch (InvalidWebhookUrl $e) {
            self::assertStringNotContainsString('secret-password', $e->getMessage());
        }
    }

    private function policy(bool $allowPrivateHosts = false): DnsWebhookUrlPolicy
    {
        return new DnsWebhookUrlPolicy($allowPrivateHosts, static fn (string $host): array => self::NAMES[$host] ?? []);
    }
}
