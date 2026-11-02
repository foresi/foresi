<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Infrastructure\Mail;

use App\Invoicing\Infrastructure\Mail\DnsMailHostPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DnsMailHostPolicyTest extends TestCase
{
    private const string PUBLIC_ADDRESS = '93.184.215.14';

    /** @var array<string, list<string>> */
    private const array NAMES = [
        'smtp.example.com' => [self::PUBLIC_ADDRESS, '2606:2800:21f:cb07:6820:80da:af6b:8b2c'],
        'inside.example.com' => ['10.0.0.1'],
        'mixed.example.com' => [self::PUBLIC_ADDRESS, '127.0.0.1'],
        'metadata.example.com' => ['169.254.169.254'],
        'loop6.example.com' => ['::1'],
        'nothing.example.com' => [],
    ];

    #[DataProvider('refused')]
    public function testLoopbackPrivateAndLinkLocalHostsAreRefused(string $host): void
    {
        self::assertFalse($this->policy()->allows($host));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refused(): iterable
    {
        yield 'IPv4 loopback' => ['127.0.0.1'];
        yield 'private' => ['10.0.0.1'];
        yield 'private 172' => ['172.16.0.5'];
        yield 'private 192' => ['192.168.1.10'];
        yield 'shared address space' => ['100.64.0.1'];
        yield 'link-local metadata' => ['169.254.169.254'];
        yield 'unspecified' => ['0.0.0.0'];
        yield 'IPv6 loopback' => ['::1'];
        yield 'IPv6 loopback in brackets' => ['[::1]'];
        yield 'IPv6 unique local' => ['fd00::1'];
        yield 'IPv6 link-local' => ['fe80::1'];
        yield 'IPv4 mapped loopback' => ['::ffff:127.0.0.1'];
        yield 'localhost' => ['localhost'];
        yield 'a name resolving to a private address' => ['inside.example.com'];
        yield 'a name resolving to link-local' => ['metadata.example.com'];
        yield 'a name resolving to IPv6 loopback' => ['loop6.example.com'];
        yield 'a name with one private address among public ones' => ['mixed.example.com'];
        yield 'a name that does not resolve' => ['nothing.example.com'];
        yield 'not a host name' => ['smtp_example.com'];
        yield 'empty' => [''];
    }

    public function testAPublicHostIsAllowed(): void
    {
        self::assertTrue($this->policy()->allows('smtp.example.com'));
        self::assertTrue($this->policy()->allows('SMTP.Example.COM.'));
        self::assertTrue($this->policy()->allows(self::PUBLIC_ADDRESS));
    }

    public function testPrivateHostsAreAllowedWhenTheInstallationSaysSo(): void
    {
        $policy = new DnsMailHostPolicy(true, $this->resolve(...));

        self::assertTrue($policy->allows('127.0.0.1'));
        self::assertTrue($policy->allows('inside.example.com'));
        self::assertFalse($policy->allows('nothing.example.com'), 'A name that does not resolve is still refused.');
    }

    private function policy(): DnsMailHostPolicy
    {
        return new DnsMailHostPolicy(false, $this->resolve(...));
    }

    /**
     * @return list<string>
     */
    private function resolve(string $host): array
    {
        return self::NAMES[$host] ?? throw new \LogicException(\sprintf('The test resolver does not know %s.', $host));
    }
}
