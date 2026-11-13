<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Infrastructure\Crypto;

use App\Shared\Application\SealerUnavailable;
use App\Shared\Infrastructure\Crypto\SodiumSealer;
use App\Webhooks\Application\WebhookSecrets;
use App\Webhooks\Domain\WebhookSecret;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The signing secrets of endpoints, sealed by the shared sodium sealer with
 * their own keys from WEBHOOK_SECRET_KEYS (see SodiumSealer for the format and
 * rotation), so the mail keys and the webhook keys are rotated apart.
 */
final readonly class SodiumWebhookSecrets implements WebhookSecrets
{
    private SodiumSealer $sealer;

    public function __construct(#[Autowire(env: 'WEBHOOK_SECRET_KEYS')] #[\SensitiveParameter] string $keys)
    {
        $this->sealer = new SodiumSealer($keys, 'webhook secrets');
    }

    public function seal(WebhookSecret $secret): string
    {
        if (!$this->sealer->isConfigured()) {
            // The sealer names the mail keys it was first written for.
            throw new SealerUnavailable('No key to seal webhook secrets is configured: set WEBHOOK_SECRET_KEYS.');
        }

        return $this->sealer->seal($secret->reveal());
    }

    public function open(string $sealed): WebhookSecret
    {
        try {
            return WebhookSecret::fromString($this->sealer->open($sealed));
        } catch (\InvalidArgumentException) {
            throw new SealerUnavailable('One of the sealed webhook secrets is not a webhook secret.');
        }
    }

    public function isConfigured(): bool
    {
        return $this->sealer->isConfigured();
    }
}
