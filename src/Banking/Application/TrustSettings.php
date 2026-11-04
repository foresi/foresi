<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

use App\Banking\Domain\InboundChannel;

/**
 * Whose word on a message is believed: the receiving servers of the path the
 * message took, named by the authserv-id they write into Authentication-Results,
 * and the forwarders whose ARC seal may vouch for a signature broken on the way.
 *
 * The receiving servers are set per channel, as the bank inbox polled over IMAP
 * and a relay that posts to the webhook are different paths with servers of their
 * own: the inbox's are never trusted for the webhook, nor the relay's for the
 * inbox. All are set by the operator; with none for a channel, no message that
 * came that way is ever verified.
 */
final readonly class TrustSettings
{
    /** @var list<string> */
    private array $authservIds;
    /** @var list<string> */
    private array $webhookAuthservIds;
    /** @var list<string> */
    private array $arcSealers;

    /**
     * @param list<string> $authservIds        the authserv-ids of the bank inbox's own receiving servers (IMAP)
     * @param list<string> $arcSealers         the signing domains (d=) of trusted ARC sealers
     * @param list<string> $webhookAuthservIds the authserv-ids of the receiving servers of the relay that posts to the webhook
     */
    public function __construct(array $authservIds, array $arcSealers, array $webhookAuthservIds = [])
    {
        $this->authservIds = $this->names($authservIds);
        $this->arcSealers = $this->names($arcSealers);
        $this->webhookAuthservIds = $this->names($webhookAuthservIds);
    }

    /**
     * Matched whole and without case, among the servers of the channel: a trusted
     * id inside a longer name is not trusted.
     */
    public function trustsAuthserv(string $authservId, InboundChannel $channel): bool
    {
        return \in_array(self::name($authservId), match ($channel) {
            InboundChannel::Imap => $this->authservIds,
            InboundChannel::Webhook => $this->webhookAuthservIds,
        }, true);
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
