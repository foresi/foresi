<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Infrastructure\Imap;

use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Connection\Responses\Data\ListData;
use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;
use DirectoryTree\ImapEngine\Connection\Tokens\Token;
use DirectoryTree\ImapEngine\Exceptions\ImapCommandException;

/**
 * The library's connection with the one command it lacks: NAMESPACE (RFC 2342),
 * which says where a user's own folders live. Courier and Cyrus keep them below
 * "INBOX.", so a folder made at the top is refused there.
 */
final class NamespacedImapConnection extends ImapConnection
{
    /**
     * The prefix and delimiter of the first personal namespace, such as
     * ["INBOX.", "."], or null when the server does not tell.
     *
     * @return array{string, string}|null
     */
    public function personalNamespace(): ?array
    {
        $this->send('NAMESPACE', tag: $tag);
        try {
            $this->assertTaggedResponse($tag);
        } catch (ImapCommandException) {
            // A server of RFC 3501 alone: its folders start at the top.
            return null;
        }
        $response = $this->result?->responses()->untagged()->first(static fn (UntaggedResponse $response): bool => $response->type()->is('NAMESPACE'));
        if (!$response instanceof UntaggedResponse) {
            return null;
        }
        // "*", "NAMESPACE", then the personal, other users' and shared namespaces.
        $personal = $response->tokenAt(2);
        $first = $personal instanceof ListData ? $personal->first() : null;
        if (!$first instanceof ListData) {
            return null;
        }
        $parts = $first->tokens();
        $prefix = $parts[0] ?? null;
        $delimiter = $parts[1] ?? null;
        if (!$prefix instanceof Token) {
            return null;
        }

        return [$prefix->value, $delimiter instanceof Token && !$delimiter->is('NIL') ? $delimiter->value : ''];
    }
}
