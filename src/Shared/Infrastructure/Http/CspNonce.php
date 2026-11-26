<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Http;

use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Attribute\AsTwigFunction;

/**
 * The nonce of the response's Content-Security-Policy: 128 random bits, new for every
 * response (CSP Level 3, "Nonce usage"; OWASP CSP Cheat Sheet), kept on the main request so
 * that sub-requests and the header use the same one. A response whose templates never ask
 * for it gets no nonce in its policy, so it runs no inline script at all.
 *
 * Templates print it as csp_nonce() on the importmap scripts and on
 * <meta name="csp-nonce">, which Turbo reads for the scripts and the progress bar style it
 * adds (base.html.twig).
 */
final readonly class CspNonce
{
    private const string ATTRIBUTE = '_foresi_csp_nonce';

    public function __construct(private RequestStack $requests)
    {
    }

    #[AsTwigFunction('csp_nonce')]
    public function get(): string
    {
        $request = $this->requests->getMainRequest();
        if (!$request instanceof \Symfony\Component\HttpFoundation\Request) {
            return '';
        }

        $nonce = $request->attributes->get(self::ATTRIBUTE);
        if (!\is_string($nonce)) {
            $nonce = base64_encode(random_bytes(16));
            $request->attributes->set(self::ATTRIBUTE, $nonce);
        }

        return $nonce;
    }

    /**
     * The nonce the response's templates used, or null when none asked for one.
     */
    public function used(): ?string
    {
        $nonce = $this->requests->getMainRequest()?->attributes->get(self::ATTRIBUTE);

        return \is_string($nonce) ? $nonce : null;
    }
}
