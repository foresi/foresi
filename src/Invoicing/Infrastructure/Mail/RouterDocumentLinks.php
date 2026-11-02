<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Mail;

use App\Invoicing\Application\DocumentLinks;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;

/**
 * Links on DEFAULT_URI, whichever host the request that queued the send came
 * through. The .env default is http://localhost, which would put a dead link in
 * every mail, so a loopback base is refused unless app.mail.allow_local_links.
 */
final readonly class RouterDocumentLinks implements DocumentLinks
{
    public const string ROUTE = 'app_document_link';

    public function __construct(
        private UrlGeneratorInterface $urls,
        #[Autowire(env: 'DEFAULT_URI')]
        private string $base,
        #[Autowire(param: 'app.mail.allow_local_links')]
        private bool $allowLocal,
    ) {
    }

    public function url(string $token): string
    {
        $context = $this->urls->getContext();
        $this->urls->setContext(RequestContext::fromUri($this->base));
        try {
            return $this->urls->generate(self::ROUTE, ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL);
        } finally {
            $this->urls->setContext($context);
        }
    }

    public function allowed(): bool
    {
        return $this->allowLocal || !$this->isLocal();
    }

    private function isLocal(): bool
    {
        $host = strtolower(trim((string) parse_url($this->base, \PHP_URL_HOST), '[]'));
        if ('' === $host || 'localhost' === $host || str_ends_with($host, '.localhost')) {
            return true;
        }
        if (false === filter_var($host, \FILTER_VALIDATE_IP)) {
            return false;
        }

        return false === filter_var($host, \FILTER_VALIDATE_IP, \FILTER_FLAG_NO_RES_RANGE) || '::1' === $host;
    }
}
