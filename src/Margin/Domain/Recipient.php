<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Domain;

/**
 * Who bears a cost: the firm itself (own) or one client, a contact.
 */
final readonly class Recipient
{
    private const string OWN = 'own';
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private function __construct(private ?string $contactId)
    {
    }

    public static function own(): self
    {
        return new self(null);
    }

    /**
     * @throws \InvalidArgumentException when the id is no uuid
     */
    public static function client(string $contactId): self
    {
        if (1 !== preg_match(self::UUID, $contactId)) {
            throw new \InvalidArgumentException('A client is a contact, named by its uuid.');
        }

        return new self(strtolower($contactId));
    }

    /**
     * The form of toString(): "own" or a contact's uuid.
     *
     * @throws \InvalidArgumentException when it is neither
     */
    public static function fromString(string $value): self
    {
        return self::OWN === $value ? self::own() : self::client($value);
    }

    public function isOwn(): bool
    {
        return null === $this->contactId;
    }

    public function contactId(): ?string
    {
        return $this->contactId;
    }

    public function toString(): string
    {
        return $this->contactId ?? self::OWN;
    }
}
