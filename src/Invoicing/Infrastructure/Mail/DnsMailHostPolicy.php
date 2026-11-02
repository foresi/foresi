<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Mail;

use App\Invoicing\Application\MailHostPolicy;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Allows a host only when every address it resolves to is a public one: no
 * loopback, private, shared, link-local (cloud metadata) or other reserved range,
 * in IPv4 or IPv6. A name that does not resolve is refused too. An installation
 * whose mail server sits in its own network sets app.mail.allow_private_hosts.
 *
 * The check runs before connecting, and the connection resolves the name again;
 * a name that changes in between (DNS rebinding) is not caught here.
 */
final readonly class DnsMailHostPolicy implements MailHostPolicy
{
    private const string HOST_NAME = '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/';

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

    public function allows(string $host): bool
    {
        $host = rtrim(strtolower(trim($host)), '.');
        $literal = trim($host, '[]');
        if (false !== filter_var($literal, \FILTER_VALIDATE_IP)) {
            return $this->allowsAddress($literal);
        }
        if (1 !== preg_match(self::HOST_NAME, $host) || 'localhost' === $host || str_ends_with($host, '.localhost')) {
            return false;
        }

        $addresses = ($this->resolve)($host);
        foreach ($addresses as $address) {
            if (!$this->allowsAddress($address)) {
                return false;
            }
        }

        return [] !== $addresses;
    }

    private function allowsAddress(string $address): bool
    {
        return $this->allowPrivateHosts || false !== filter_var($address, \FILTER_VALIDATE_IP, \FILTER_FLAG_GLOBAL_RANGE);
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
