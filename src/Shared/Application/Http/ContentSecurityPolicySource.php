<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\Http;

/**
 * What a module adds to the Content-Security-Policy of every page, such as the host of an
 * outside service its pages load or post to. Each service that implements it is tagged
 * (the Shared module's services.php) and read by the security headers; the core policy is
 * never edited for a module (docs/operations.md, "Security headers").
 *
 * Only these directives take additions: connect-src, font-src, form-action, frame-src,
 * img-src, media-src, script-src, style-src and worker-src. A source is a host such as
 * https://api.example.com, 'self', or data: and blob: where a page needs them for images,
 * media, fonts or workers. Keywords that switch protection off ('unsafe-inline',
 * 'unsafe-eval' and the like), * and bare schemes such as https: are refused, and so is a
 * source with a space, a comma or a semicolon in it.
 */
interface ContentSecurityPolicySource
{
    public const string TAG = 'foresi.shared.content_security_policy_source';

    /**
     * @return array<string, list<string>> sources by directive name
     */
    public function sources(): array;
}
