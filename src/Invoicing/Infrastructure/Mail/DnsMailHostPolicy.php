<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Mail;

use App\Invoicing\Application\MailHostPolicy;
use App\Invoicing\Application\MailRefused;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Allows a host only when every address it resolves to is a public one: no
 * loopback, private, shared, link-local (cloud metadata) or other reserved range,
 * in IPv4 or IPv6, and never a multicast, site-local, NAT64, 6to4 or IPv4-embedding
 * IPv6 address, which could lead to any IPv4 host. The address vetted is the one
 * connected to, so a name that changes between check and connection (DNS
 * rebinding) changes nothing. An installation whose mail server sits in its own
 * network sets app.mail.allow_private_hosts; the embedding and multicast ranges
 * stay refused even then.
 */
final readonly class DnsMailHostPolicy implements MailHostPolicy
{
    private const string HOST_NAME = '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/';

    /** Refused always: multicast, IPv6 site-local, NAT64, 6to4, IPv4-compatible and IPv4-mapped IPv6. */
    private const array ALWAYS_REFUSED = ['224.0.0.0/4', 'ff00::/8', 'fec0::/10', '64:ff9b::/96', '64:ff9b:1::/48', '2002::/16', '::/96', '::ffff:0:0/96'];

    /** @var \Closure(string): list<string> */
    private \Closure $resolve;

    /**
     * @param (\Closure(string): list<string>)|null $resolver the addresses of a host name; DNS when null
     */
    public function __construct(
        #[Autowire(param: 'app.mail.allow_private_hosts')]
        private bool $allowPrivateHosts,
        ?\Closure $resolver = null,
    ) {
        $this->resolve = $resolver ?? $this->lookUp(...);
    }

    public function vet(string $host): string
    {
        $host = rtrim(strtolower(trim($host)), '.');
        $literal = trim($host, '[]');
        if (false !== filter_var($literal, \FILTER_VALIDATE_IP)) {
            return $this->allowsAddress($literal) ? $literal : throw $this->forbidden();
        }
        // A localhost name is its loopback address: refused or allowed with it, never on its own rule.
        if (1 !== preg_match(self::HOST_NAME, $host) || (!$this->allowPrivateHosts && ('localhost' === $host || str_ends_with($host, '.localhost')))) {
            throw $this->forbidden();
        }

        $addresses = ($this->resolve)($host);
        foreach ($addresses as $address) {
            if (!$this->allowsAddress($address)) {
                throw $this->forbidden();
            }
        }

        return $addresses[0] ?? throw new MailRefused(false, 'The mail server name could not be resolved.', reasonCode: MailRefused::UNRESOLVED);
    }

    public function allows(string $host): bool
    {
        try {
            $this->vet($host);

            return true;
        } catch (MailRefused) {
            return false;
        }
    }

    private function forbidden(): MailRefused
    {
        return new MailRefused(true, 'The mail server address is not allowed.', true, MailRefused::HOST_NOT_ALLOWED);
    }

    private function allowsAddress(string $address): bool
    {
        $binary = @inet_pton($address);
        if (false === $binary) {
            return false;
        }
        // The IPv6 loopback lies inside the IPv4-compatible range refused below, but it is
        // loopback like 127.0.0.1 and follows the same setting.
        if ($binary === inet_pton('::1')) {
            return $this->allowPrivateHosts;
        }
        foreach (self::ALWAYS_REFUSED as $range) {
            if ($this->inRange($binary, $range)) {
                return false;
            }
        }

        return $this->allowPrivateHosts || false !== filter_var($address, \FILTER_VALIDATE_IP, \FILTER_FLAG_GLOBAL_RANGE);
    }

    private function inRange(string $binary, string $range): bool
    {
        [$network, $bits] = explode('/', $range);
        $prefix = (string) inet_pton($network);
        if (\strlen($prefix) !== \strlen($binary)) {
            return false;
        }
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (0 !== strncmp($binary, $prefix, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if (0 === $rest) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (\ord($binary[$bytes]) & $mask) === (\ord($prefix[$bytes]) & $mask);
    }

    /**
     * @return list<string>
     */
    private function lookUp(string $host): array
    {
        $found = gethostbynamel($host);
        $addresses = false === $found ? [] : $found;
        $records = @dns_get_record($host, \DNS_AAAA);
        foreach (false === $records ? [] : $records as $record) {
            if (isset($record['ipv6']) && \is_string($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return $addresses;
    }
}
