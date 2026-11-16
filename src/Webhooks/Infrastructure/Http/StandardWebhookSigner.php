<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Infrastructure\Http;

use App\Webhooks\Domain\WebhookSecret;

/**
 * The three Standard Webhooks headers. The signature is "v1," and the base64 of
 * HMAC-SHA256 over "{id}.{timestamp}.{body}" with the secret's decoded bytes;
 * while a secret is being rotated, both sign and the signatures are separated
 * by a space.
 */
final readonly class StandardWebhookSigner
{
    /**
     * @param list<WebhookSecret> $secrets
     *
     * @return array<string, string>
     */
    public function headers(string $id, int $timestamp, string $body, array $secrets): array
    {
        $content = $id.'.'.$timestamp.'.'.$body;
        $signatures = array_map(
            static fn (WebhookSecret $secret): string => 'v1,'.base64_encode(hash_hmac('sha256', $content, $secret->key(), true)),
            $secrets,
        );

        return [
            'webhook-id' => $id,
            'webhook-timestamp' => (string) $timestamp,
            'webhook-signature' => implode(' ', $signatures),
        ];
    }
}
