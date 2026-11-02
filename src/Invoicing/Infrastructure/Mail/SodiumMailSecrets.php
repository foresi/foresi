<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Mail;

use App\Invoicing\Application\MailSecrets;
use App\Invoicing\Application\MailSecretsUnavailable;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * libsodium secretbox (XSalsa20-Poly1305) with keys from MAIL_SECRET_KEYS:
 * "id:base64key,id:base64key", the first sealing, all opening, so a key can be
 * rotated while old seals still open. A seal is "id:base64(nonce + box)". A list
 * with any broken entry counts as no keys at all, so a typo never seals with a
 * key other than the one meant. The keys are not derived from APP_SECRET.
 */
final readonly class SodiumMailSecrets implements MailSecrets
{
    private const string KEY_ID = '/^[A-Za-z0-9_-]{1,32}$/';

    /** @var array<string, string> raw keys by id, the sealing key first */
    private array $keys;

    public function __construct(#[Autowire(env: 'MAIL_SECRET_KEYS')] string $keys)
    {
        $this->keys = $this->parse($keys);
    }

    public function seal(string $secret): string
    {
        $id = array_key_first($this->keys) ?? throw new MailSecretsUnavailable('No key to seal mail server passwords is configured: set MAIL_SECRET_KEYS.');
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return $id.':'.base64_encode($nonce.sodium_crypto_secretbox($secret, $nonce, $this->keys[$id]));
    }

    public function open(string $sealed): string
    {
        $parts = explode(':', $sealed, 2);
        $key = 2 === \count($parts) ? ($this->keys[$parts[0]] ?? null) : null;
        if (null === $key) {
            throw new MailSecretsUnavailable('The key that sealed this mail server password is not configured.');
        }
        $raw = base64_decode($parts[1], true);
        if (false === $raw || \strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new MailSecretsUnavailable('The sealed mail server password is damaged.');
        }
        $opened = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $key);

        return false === $opened ? throw new MailSecretsUnavailable('The configured key does not open this mail server password.') : $opened;
    }

    public function isConfigured(): bool
    {
        return [] !== $this->keys;
    }

    /**
     * @return array<string, string>
     */
    private function parse(string $list): array
    {
        $keys = [];
        foreach (explode(',', $list) as $entry) {
            $entry = trim($entry);
            if ('' === $entry) {
                continue;
            }
            $parts = explode(':', $entry, 2);
            $key = 2 === \count($parts) ? base64_decode($parts[1], true) : false;
            if (false === $key || SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== \strlen($key) || 1 !== preg_match(self::KEY_ID, $parts[0]) || isset($keys[$parts[0]])) {
                return [];
            }
            $keys[$parts[0]] = $key;
        }

        return $keys;
    }
}
