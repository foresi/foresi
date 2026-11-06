<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Infrastructure\Http;

use App\Idoklad\Application\IdokladClient;
use App\Idoklad\Application\IdokladSession;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class HttpIdokladClient implements IdokladClient
{
    public function __construct(
        private HttpClientInterface $http,
        private ClockInterface $clock,
    ) {
    }

    public function session(string $clientId, #[\SensitiveParameter] string $clientSecret): IdokladSession
    {
        return new HttpIdokladSession($this->http, $this->clock, $clientId, $clientSecret);
    }
}
