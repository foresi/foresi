<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Infrastructure\Http;

use App\Webhooks\Application\InvalidWebhookUrl;
use App\Webhooks\Application\WebhookUrlPolicy;
use App\Webhooks\Domain\EndpointUrl;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Allows an https URL only when every address its host resolves to, A and
 * AAAA, lies outside IpUtils::PRIVATE_SUBNETS: the ranges the HTTP client's
 * NoPrivateNetworkHttpClient refuses when it sends, so what is saved is what
 * can be sent. The check on save tells the person at once; the one on send is
 * what holds against a name that changes later (DNS rebinding). An
 * installation that posts to its own network, such as for local development,
 * sets app.webhooks.allow_private_hosts.
 */
final readonly class DnsWebhookUrlPolicy implements WebhookUrlPolicy
{
    private const string HOST_NAME = '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/';

    /** @var \Closure(string): list<string> */
    private \Closure $resolve;

    /**
     * @param (\Closure(string): list<string>)|null $resolver the addresses of a host name; DNS when null
     */
    public function __construct(
        #[Autowire(param: 'app.webhooks.allow_private_hosts')]
        private bool $allowPrivateHosts,
        ?\Closure $resolver = null,
    ) {
        $this->resolve = $resolver ?? $this->lookUp(...);
    }

    public function vet(string $url): EndpointUrl
    {
        $url = trim($url);
        if (\strlen($url) > EndpointUrl::MAX_LENGTH) {
            throw new InvalidWebhookUrl(InvalidWebhookUrl::LENGTH);
        }
        if (1 !== preg_match('~^https://~i', $url)) {
            throw new InvalidWebhookUrl(InvalidWebhookUrl::SCHEME);
        }
        $parts = parse_url($url);
        if (false === $parts || '' === ($parts['host'] ?? '')) {
            throw new InvalidWebhookUrl(InvalidWebhookUrl::HOST);
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidWebhookUrl(InvalidWebhookUrl::USERINFO);
        }

        $host = rtrim(strtolower($parts['host']), '.');
        $literal = trim($host, '[]');
        if (false !== filter_var($literal, \FILTER_VALIDATE_IP)) {
            $this->vetAddress($literal);

            return new EndpointUrl($url);
        }
        if (1 !== preg_match(self::HOST_NAME, $host)) {
            throw new InvalidWebhookUrl(InvalidWebhookUrl::HOST);
        }
        // A localhost name is its loopback address: refused or allowed with it, never on its own rule.
        if (!$this->allowPrivateHosts && ('localhost' === $host || str_ends_with($host, '.localhost'))) {
            throw new InvalidWebhookUrl(InvalidWebhookUrl::PRIVATE_ADDRESS);
        }

        $addresses = ($this->resolve)($host);
        if ([] === $addresses) {
            throw new InvalidWebhookUrl(InvalidWebhookUrl::UNRESOLVABLE);
        }
        foreach ($addresses as $address) {
            $this->vetAddress($address);
        }

        return new EndpointUrl($url);
    }

    private function vetAddress(string $address): void
    {
        if (false === filter_var($address, \FILTER_VALIDATE_IP)) {
            throw new InvalidWebhookUrl(InvalidWebhookUrl::PRIVATE_ADDRESS);
        }
        if (!$this->allowPrivateHosts && IpUtils::checkIp($address, IpUtils::PRIVATE_SUBNETS)) {
            throw new InvalidWebhookUrl(InvalidWebhookUrl::PRIVATE_ADDRESS);
        }
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
