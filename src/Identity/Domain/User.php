<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * A person who can log in. A user is global identity, not owned by a tenant:
 * which tenants they work in is decided by their memberships.
 */
#[ORM\Entity]
#[ORM\Table(name: 'app_user')]
class User
{
    public const array LOCALES = ['cs', 'en'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255, unique: true)]
    private string $email;

    #[ORM\Column(name: 'display_name', type: 'string', length: 255)]
    private string $displayName;

    #[ORM\Column(type: 'string', length: 2)]
    private string $locale;

    public function __construct(
        string $email,
        #[ORM\Column(name: 'password_hash', type: 'string', length: 255)]
        private string $passwordHash,
        string $displayName,
        string $locale = 'en',
    ) {
        $email = strtolower(trim($email));
        if (false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('A user needs a valid e-mail address.');
        }
        if ('' === $passwordHash) {
            throw new \InvalidArgumentException('A user needs a password hash.');
        }
        $displayName = trim($displayName);
        if ('' === $displayName) {
            throw new \InvalidArgumentException('A user needs a display name.');
        }
        if (!\in_array($locale, self::LOCALES, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported locale "%s".', $locale));
        }

        $this->email = $email;
        $this->displayName = $displayName;
        $this->locale = $locale;
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('The user has not been persisted yet.');
    }

    public function email(): string
    {
        return $this->email;
    }

    public function passwordHash(): string
    {
        return $this->passwordHash;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    public function locale(): string
    {
        return $this->locale;
    }
}
