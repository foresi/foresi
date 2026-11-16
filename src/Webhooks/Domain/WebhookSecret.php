<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Domain;

/**
 * The signing secret of an endpoint as Standard Webhooks writes it: "whsec_"
 * and the base64 of 24 to 64 random bytes; the bytes are the HMAC key. There
 * is no string cast and a dump shows nothing, so the secret leaves only where
 * reveal() is called on purpose.
 */
final readonly class WebhookSecret
{
    private const string PREFIX = 'whsec_';
    private const int GENERATED_BYTES = 32;
    private const int MIN_BYTES = 24;
    private const int MAX_BYTES = 64;

    private function __construct(#[\SensitiveParameter] private string $key)
    {
    }

    public static function generate(): self
    {
        return new self(random_bytes(self::GENERATED_BYTES));
    }

    public static function fromString(#[\SensitiveParameter] string $secret): self
    {
        $key = str_starts_with($secret, self::PREFIX) ? base64_decode(substr($secret, \strlen(self::PREFIX)), true) : false;
        if (false === $key || \strlen($key) < self::MIN_BYTES || \strlen($key) > self::MAX_BYTES) {
            throw new \InvalidArgumentException(\sprintf('A webhook secret is "%s" and the base64 of %d to %d bytes.', self::PREFIX, self::MIN_BYTES, self::MAX_BYTES));
        }

        return new self($key);
    }

    /**
     * The raw bytes: the HMAC key.
     */
    public function key(): string
    {
        return $this->key;
    }

    /**
     * The secret as the receiver keeps it, for the responses that hand it over.
     */
    public function reveal(): string
    {
        return self::PREFIX.base64_encode($this->key);
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['key' => '[hidden]'];
    }
}
