<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

/**
 * Whose word on a message is believed: the receiving servers of the operator's
 * bank inbox, named by the authserv-id they write into Authentication-Results,
 * and the forwarders whose ARC seal may vouch for a signature broken on the way.
 * Both are set by the operator; with none, no message is ever verified.
 */
final readonly class TrustSettings
{
    /** @var list<string> */
    private array $authservIds;
    /** @var list<string> */
    private array $arcSealers;

    /**
     * @param list<string> $authservIds the authserv-ids of the inbox's own receiving servers
     * @param list<string> $arcSealers  the signing domains (d=) of trusted ARC sealers
     */
    public function __construct(array $authservIds, array $arcSealers)
    {
        $this->authservIds = $this->names($authservIds);
        $this->arcSealers = $this->names($arcSealers);
    }

    /**
     * Matched whole and without case: a trusted id inside a longer name is not trusted.
     */
    public function trustsAuthserv(string $authservId): bool
    {
        return \in_array(self::name($authservId), $this->authservIds, true);
    }

    public function trustsArcSealer(string $domain): bool
    {
        return \in_array(self::name($domain), $this->arcSealers, true);
    }

    /**
     * A name as compared: trimmed, lowercased, without the root dot.
     */
    public static function name(string $name): string
    {
        return strtolower(rtrim(trim($name), '.'));
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    private function names(array $names): array
    {
        return array_values(array_filter(array_map(self::name(...), $names), static fn (string $name): bool => '' !== $name));
    }
}
