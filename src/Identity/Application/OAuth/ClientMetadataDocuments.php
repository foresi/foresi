<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Application\OAuth;

/**
 * Reads a client ID metadata document from the internet: https on a public
 * address only, no redirects, a short time and a small size.
 */
interface ClientMetadataDocuments
{
    /**
     * @return array<string, mixed> the decoded JSON object
     *
     * @throws OAuthRefused when the document cannot be read or is not a JSON object
     */
    public function fetch(string $url): array;
}
