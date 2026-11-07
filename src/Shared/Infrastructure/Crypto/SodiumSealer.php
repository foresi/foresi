<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Crypto;

use App\Shared\Application\Sealer;
use App\Shared\Application\SealerUnavailable;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * libsodium secretbox (XSalsa20-Poly1305) with keys from MAIL_SECRET_KEYS (named
 * after its first use): "id:base64key,id:base64key", the first sealing, all
 * opening, so a key can be rotated while old seals still open. A seal is
 * "id:base64(nonce + box)". A list with any broken entry counts as no keys at
 * all, so a typo never seals with a key other than the one meant. The keys are
 * not derived from APP_SECRET.
 */
final readonly class SodiumSealer implements Sealer
{
    private const string KEY_ID = '/^[A-Za-z0-9_-]{1,32}$/';

    /** @var array<string, string> raw keys by id, the sealing key first */
    private array $keys;

    /**
     * @param string $what what is sealed, plural, for the messages of a refusal
     */
    public function __construct(
        #[Autowire(env: 'MAIL_SECRET_KEYS')] #[\SensitiveParameter] string $keys,
        private string $what = 'secrets',
    ) {
        $this->keys = $this->parse($keys);
    }

    public function seal(#[\SensitiveParameter] string $secret): string
    {
        $id = array_key_first($this->keys) ?? throw new SealerUnavailable(sprintf('No key to seal %s is configured: set MAIL_SECRET_KEYS.', $this->what));
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return $id.':'.base64_encode($nonce.sodium_crypto_secretbox($secret, $nonce, $this->keys[$id]));
    }

    public function open(string $sealed): string
    {
        $parts = explode(':', $sealed, 2);
        $key = 2 === \count($parts) ? ($this->keys[$parts[0]] ?? null) : null;
        if (null === $key) {
            throw new SealerUnavailable(sprintf('The key that sealed one of the %s is not configured.', $this->what));
        }
        $raw = base64_decode($parts[1], true);
        if (false === $raw || \strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new SealerUnavailable(sprintf('One of the sealed %s is damaged.', $this->what));
        }
        $opened = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $key);

        return false === $opened ? throw new SealerUnavailable(sprintf('The configured key does not open one of the %s.', $this->what)) : $opened;
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
