<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Domain;

/**
 * The sender a forwarded mail names in its forward block: the forwarder's own
 * words about who wrote the original, never verified by anyone.
 */
final readonly class ForwardedSender
{
    public const int MAX_ADDRESS = 320;

    public string $address;

    /**
     * @param string      $address an e-mail address, kept lower case
     * @param string|null $name    the display name the block gives, if any
     */
    public function __construct(string $address, public ?string $name = null)
    {
        $address = mb_strtolower(trim($address));
        if ('' === $address || mb_strlen($address) > self::MAX_ADDRESS || !str_contains($address, '@')) {
            throw new \InvalidArgumentException('A forwarded sender has an e-mail address of up to 320 characters.');
        }
        $this->address = $address;
    }
}
