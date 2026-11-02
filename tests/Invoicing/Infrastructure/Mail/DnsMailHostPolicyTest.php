<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Infrastructure\Mail;

use App\Invoicing\Application\MailRefused;
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
        'nat64.example.com' => ['64:ff9b::a00:1'],
        'multicast.example.com' => ['239.1.1.1'],
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
        yield 'NAT64 of loopback' => ['64:ff9b::7f00:1'];
        yield 'local NAT64' => ['64:ff9b:1::a00:1'];
        yield '6to4 of loopback' => ['2002:7f00:1::1'];
        yield 'IPv4-compatible loopback' => ['::127.0.0.1'];
        yield 'IPv4-mapped public address' => ['::ffff:93.184.215.14'];
        yield 'IPv6 site-local' => ['fec0::1'];
        yield 'IPv4 multicast' => ['224.0.0.1'];
        yield 'IPv6 multicast' => ['ff02::1'];
        yield 'broadcast' => ['255.255.255.255'];
        yield 'a name resolving to NAT64' => ['nat64.example.com'];
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

    public function testVetGivesTheAddressToConnectTo(): void
    {
        self::assertSame(self::PUBLIC_ADDRESS, $this->policy()->vet('smtp.example.com'));
        self::assertSame(self::PUBLIC_ADDRESS, $this->policy()->vet(self::PUBLIC_ADDRESS));
        self::assertSame('2606:2800:21f:cb07:6820:80da:af6b:8b2c', $this->policy()->vet('[2606:2800:21f:cb07:6820:80da:af6b:8b2c]'));
    }

    public function testAForbiddenAddressIsAPermanentSettingsProblem(): void
    {
        try {
            $this->policy()->vet('inside.example.com');
            self::fail('A private address must be refused.');
        } catch (MailRefused $refused) {
            self::assertTrue($refused->permanent);
            self::assertTrue($refused->settingsProblem);
            self::assertSame('The mail server address is not allowed.', $refused->safeReason);
        }
    }

    public function testANameThatDoesNotResolveIsTriedAgain(): void
    {
        try {
            $this->policy()->vet('nothing.example.com');
            self::fail('A name without an address cannot be connected to.');
        } catch (MailRefused $refused) {
            self::assertFalse($refused->permanent, 'A resolver timeout must not fail a send for good.');
            self::assertSame('The mail server name could not be resolved.', $refused->safeReason);
        }
    }

    public function testPrivateHostsAreAllowedWhenTheInstallationSaysSo(): void
    {
        $policy = new DnsMailHostPolicy(true, $this->resolve(...));

        self::assertTrue($policy->allows('127.0.0.1'));
        self::assertTrue($policy->allows('inside.example.com'));
        self::assertFalse($policy->allows('nothing.example.com'), 'A name that does not resolve is still refused.');
        self::assertFalse($policy->allows('nat64.example.com'), 'An address that can lead anywhere stays refused.');
        self::assertFalse($policy->allows('multicast.example.com'));
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
