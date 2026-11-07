<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Mail;

use App\Invoicing\Application\MailSecrets;
use App\Invoicing\Application\MailSecretsUnavailable;
use App\Shared\Application\SealerUnavailable;
use App\Shared\Infrastructure\Crypto\SodiumSealer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The passwords of mail servers, sealed by the shared sodium sealer with the
 * keys from MAIL_SECRET_KEYS (see SodiumSealer for the format and rotation).
 */
final readonly class SodiumMailSecrets implements MailSecrets
{
    private SodiumSealer $sealer;

    public function __construct(#[Autowire(env: 'MAIL_SECRET_KEYS')] #[\SensitiveParameter] string $keys)
    {
        $this->sealer = new SodiumSealer($keys, 'mail server passwords');
    }

    public function seal(#[\SensitiveParameter] string $secret): string
    {
        try {
            return $this->sealer->seal($secret);
        } catch (SealerUnavailable $e) {
            throw new MailSecretsUnavailable($e->getMessage(), 0, $e);
        }
    }

    public function open(string $sealed): string
    {
        try {
            return $this->sealer->open($sealed);
        } catch (SealerUnavailable $e) {
            throw new MailSecretsUnavailable($e->getMessage(), 0, $e);
        }
    }

    public function isConfigured(): bool
    {
        return $this->sealer->isConfigured();
    }
}
