<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Http;

use Foresi\Shared\Application\Http\ContentSecurityPolicySource;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\Request;

/**
 * The Content-Security-Policy of every page that does not set its own.
 *
 * - Scripts run only with the response's nonce; 'strict-dynamic' lets the importmap's module
 *   load the modules it imports, including the data: entries AssetMapper writes for CSS
 *   (Symfony AssetMapper, "Content Security Policy"), and 'self' is the fallback of browsers
 *   without it. object-src and base-uri are 'none' (OWASP ASVS 5.0 3.4.3; OWASP CSP Cheat
 *   Sheet, "Strict policy").
 * - Style sheets come from the application or carry the nonce (Turbo's progress bar).
 *   style="" attributes are allowed: the components size and colour with them, and an
 *   attribute can carry no script (MDN, style-src-attr).
 * - Only the application frames its pages (frame-ancestors, OWASP ASVS 5.0 3.4.6), and forms
 *   post only to it.
 *
 * Modules add hosts through ContentSecurityPolicySource; an operator may add a report-uri
 * (CSP_REPORT_URI, ASVS 3.4.7).
 *
 * One page may let its form end somewhere else: the OAuth consent screen answers with a
 * redirect to the client, and browsers check form-action on every redirect after a submit
 * (CSP Level 3, "form-action"; Chrome blocks it). allowFormRedirect() adds the origin of that
 * one redirect to form-action of that one response; every other page keeps 'self'.
 */
final class ContentSecurityPolicy
{
    private const array BASE = [
        'default-src' => ["'self'"],
        'script-src' => ["'self'"],
        'style-src' => ["'self'"],
        'style-src-attr' => ["'unsafe-inline'"],
        'img-src' => ["'self'", 'data:'],
        'object-src' => ["'none'"],
        'base-uri' => ["'none'"],
        'form-action' => ["'self'"],
        'frame-ancestors' => ["'self'"],
    ];

    /** The directives a module may add to. */
    private const array EXTENSIBLE = ['connect-src', 'font-src', 'form-action', 'frame-src', 'img-src', 'media-src', 'script-src', 'style-src', 'worker-src'];

    /** The request attribute with the redirect a form of the page may end in. */
    public const string FORM_REDIRECT = '_csp_form_redirect';

    /** Schemes a form may never be redirected to, though a client registered them. */
    private const array REFUSED_REDIRECT_SCHEMES = ['about', 'blob', 'data', 'file', 'filesystem', 'javascript', 'vbscript'];

    /** Where data: and blob: may be added. */
    private const array DATA_SCHEMES = ['font-src', 'img-src', 'media-src', 'worker-src'];

    /** @var array<string, list<string>>|null */
    private ?array $directives = null;

    /**
     * @param iterable<ContentSecurityPolicySource> $sources
     */
    public function __construct(
        #[AutowireIterator(ContentSecurityPolicySource::TAG)]
        private readonly iterable $sources,
        private readonly string $reportUri = '',
    ) {
    }

    /**
     * Lets a form of the page that answers this request end in a redirect to $uri: its origin,
     * or its scheme for a private-use URI such as cursor://callback, joins form-action.
     */
    public static function allowFormRedirect(Request $request, string $uri): void
    {
        $request->attributes->set(self::FORM_REDIRECT, $uri);
    }

    /**
     * The header value, with the nonce of the response when its templates used one and the
     * redirect a form of the page may end in.
     */
    public function header(?string $nonce, ?string $formRedirect = null): string
    {
        $directives = $this->directives();
        $redirectSource = null === $formRedirect ? null : $this->redirectSource($formRedirect);
        if (null !== $redirectSource && !\in_array($redirectSource, $directives['form-action'], true)) {
            $directives['form-action'][] = $redirectSource;
        }
        if (null !== $nonce) {
            $directives['script-src'][] = "'nonce-".$nonce."'";
            $directives['script-src'][] = "'strict-dynamic'";
            $directives['style-src'][] = "'nonce-".$nonce."'";
        }
        if ('' !== $this->reportUri) {
            $directives['report-uri'] = [$this->reportUri];
        }

        $parts = [];
        foreach ($directives as $name => $values) {
            $parts[] = $name.' '.implode(' ', $values);
        }

        return implode('; ', $parts);
    }

    /**
     * @return array<string, list<string>>
     */
    private function directives(): array
    {
        if (null !== $this->directives) {
            return $this->directives;
        }

        $directives = self::BASE;
        foreach ($this->sources as $source) {
            foreach ($source->sources() as $directive => $values) {
                if (!\in_array($directive, self::EXTENSIBLE, true)) {
                    throw new \LogicException(\sprintf('%s may not add to the Content-Security-Policy directive "%s".', $source::class, $directive));
                }
                foreach ($values as $value) {
                    if (!$this->allowed($directive, $value)) {
                        throw new \LogicException(\sprintf('%s may not add the source "%s" to "%s" of the Content-Security-Policy.', $source::class, $value, $directive));
                    }
                    $directives[$directive] ??= self::BASE['default-src'];
                    if (!\in_array($value, $directives[$directive], true)) {
                        $directives[$directive][] = $value;
                    }
                }
            }
        }

        return $this->directives = $directives;
    }

    private function allowed(string $directive, string $value): bool
    {
        if ('' === $value || 1 === preg_match('/[\s,;]/', $value)) {
            return false;
        }
        if ("'self'" === $value) {
            return true;
        }
        if ('data:' === $value || 'blob:' === $value) {
            return \in_array($directive, self::DATA_SCHEMES, true);
        }

        // A host with a scheme and nothing that widens it to every host.
        return 1 === preg_match('~^(https|wss)://(\*\.)?[a-z0-9-]+(\.[a-z0-9-]+)+(:\d{1,5})?(/[^\s,;\']*)?$~i', $value);
    }

    /**
     * The source that matches a redirect: the origin of an http or https URL, the scheme of a
     * private-use one. Nothing for a URL a source cannot express exactly, such as an IPv6
     * loopback address (CSP Level 3, "host-source" has no IP literals), or one that would run
     * code.
     */
    private function redirectSource(string $uri): ?string
    {
        $parts = parse_url($uri);
        if (false === $parts || !isset($parts['scheme'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        if ('http' === $scheme || 'https' === $scheme) {
            $host = strtolower($parts['host'] ?? '');
            if (1 !== preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)*$/', $host)) {
                return null;
            }

            return $scheme.'://'.$host.(isset($parts['port']) ? ':'.$parts['port'] : '');
        }
        if (1 !== preg_match('/^[a-z][a-z0-9+.-]*$/', $scheme) || \in_array($scheme, self::REFUSED_REDIRECT_SCHEMES, true)) {
            return null;
        }

        return $scheme.':';
    }
}
