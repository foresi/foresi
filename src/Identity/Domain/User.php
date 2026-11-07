<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Domain;

use App\Shared\Domain\Id;
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
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'string', length: 255, unique: true)]
    private string $email;

    #[ORM\Column(name: 'display_name', type: 'string', length: 255)]
    private string $displayName;

    #[ORM\Column(type: 'string', length: 2)]
    private string $locale;

    /** The TOTP secret, sealed with keys held outside the database; null until enrolled. */
    #[ORM\Column(name: 'totp_secret_sealed', type: 'text', nullable: true)]
    private ?string $totpSecretSealed = null;

    /** @var list<string> SHA-256 hashes of the unused recovery codes */
    #[ORM\Column(name: 'recovery_code_hashes', type: 'json', options: ['default' => '[]'])]
    private array $recoveryCodeHashes = [];

    /** Raised on a reset, so every browser trusted before signs in with a code again. */
    #[ORM\Column(name: 'trusted_device_version', type: 'integer', options: ['default' => 0])]
    private int $trustedDeviceVersion = 0;

    public function __construct(
        string $email,
        #[ORM\Column(name: 'password_hash', type: 'string', length: 255)]
        private string $passwordHash,
        string $displayName,
        string $locale = 'en',
    ) {
        $this->id = Id::generate();
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
        $this->assertSupported($locale);

        $this->email = $email;
        $this->displayName = $displayName;
        $this->locale = $locale;
    }

    public function changeLocale(string $locale): void
    {
        $this->assertSupported($locale);
        $this->locale = $locale;
    }

    /**
     * @param list<string> $recoveryCodeHashes
     */
    public function enrolTwoFactor(string $totpSecretSealed, array $recoveryCodeHashes): void
    {
        if ('' === $totpSecretSealed) {
            throw new \InvalidArgumentException('Two-factor sign-in needs a sealed secret.');
        }
        if ([] === $recoveryCodeHashes) {
            throw new \InvalidArgumentException('Two-factor sign-in needs recovery codes.');
        }
        $this->totpSecretSealed = $totpSecretSealed;
        $this->recoveryCodeHashes = array_values(array_unique($recoveryCodeHashes));
    }

    /**
     * The user enrols again on the next sign-in; trusted browsers are forgotten.
     */
    public function resetTwoFactor(): void
    {
        $this->totpSecretSealed = null;
        $this->recoveryCodeHashes = [];
        ++$this->trustedDeviceVersion;
    }

    public function hasTwoFactor(): bool
    {
        return null !== $this->totpSecretSealed;
    }

    public function totpSecretSealed(): ?string
    {
        return $this->totpSecretSealed;
    }

    public function hasRecoveryCode(string $hash): bool
    {
        return \in_array($hash, $this->recoveryCodeHashes, true);
    }

    /**
     * A recovery code works once.
     */
    public function useRecoveryCode(string $hash): bool
    {
        if (!$this->hasRecoveryCode($hash)) {
            return false;
        }
        $this->recoveryCodeHashes = array_values(array_filter($this->recoveryCodeHashes, static fn (string $kept): bool => $kept !== $hash));

        return true;
    }

    public function recoveryCodesLeft(): int
    {
        return \count($this->recoveryCodeHashes);
    }

    public function trustedDeviceVersion(): int
    {
        return $this->trustedDeviceVersion;
    }

    public function id(): string
    {
        return $this->id;
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

    private function assertSupported(string $locale): void
    {
        if (!\in_array($locale, self::LOCALES, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported locale "%s".', $locale));
        }
    }
}
