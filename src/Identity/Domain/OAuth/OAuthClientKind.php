<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Domain\OAuth;

/**
 * How Foresi knows an OAuth client: from its client ID metadata document, or
 * from a dynamic client registration (RFC 7591).
 */
enum OAuthClientKind: string
{
    case Metadata = 'cimd';
    case Registered = 'dcr';
}
