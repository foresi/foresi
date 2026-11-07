<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Domain\User;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfiguration;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfigurationInterface;
use Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface;
use Scheb\TwoFactorBundle\Model\TrustedDeviceInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The signed-in identity as the Symfony firewall sees it. Tenant roles are
 * membership data; every authenticated user only has ROLE_USER here. The
 * opened TOTP secret lives only for the request: the session never holds it.
 */
final readonly class SecurityUser implements UserInterface, PasswordAuthenticatedUserInterface, TwoFactorInterface, TrustedDeviceInterface
{
    public const int TOTP_DIGITS = 6;
    public const int TOTP_PERIOD = 30;

    public function __construct(
        private string $id,
        private string $email,
        private string $passwordHash,
        #[\SensitiveParameter] private ?string $totpSecret = null,
        private int $trustedDeviceVersion = 0,
    ) {
    }

    public static function fromUser(User $user, #[\SensitiveParameter] ?string $totpSecret = null): self
    {
        return new self($user->id(), $user->email(), $user->passwordHash(), $totpSecret, $user->trustedDeviceVersion());
    }

    /**
     * The same user with a secret not yet confirmed, to check the first code of an enrolment.
     */
    public function withTotpSecret(#[\SensitiveParameter] string $totpSecret): self
    {
        return new self($this->id, $this->email, $this->passwordHash, $totpSecret, $this->trustedDeviceVersion);
    }

    public function hasTwoFactor(): bool
    {
        return null !== $this->totpSecret;
    }

    public function isTotpAuthenticationEnabled(): bool
    {
        return $this->hasTwoFactor();
    }

    public function getTotpAuthenticationUsername(): string
    {
        return $this->getUserIdentifier();
    }

    public function getTotpAuthenticationConfiguration(): ?TotpConfigurationInterface
    {
        return null === $this->totpSecret || '' === $this->totpSecret ? null : new TotpConfiguration($this->totpSecret, TotpConfiguration::ALGORITHM_SHA1, self::TOTP_PERIOD, self::TOTP_DIGITS);
    }

    public function getTrustedTokenVersion(): int
    {
        return $this->trustedDeviceVersion;
    }

    public function id(): string
    {
        return $this->id;
    }

    /**
     * @return non-empty-string
     */
    public function getUserIdentifier(): string
    {
        return '' !== $this->email ? $this->email : throw new \LogicException('A user always has an e-mail address.');
    }

    public function getPassword(): string
    {
        return $this->passwordHash;
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
        // Nothing to erase: the plain password is never kept.
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        // The session never holds the real hash; Symfony compares the crc32c.
        // Nor the TOTP secret: the firewall loads the user afresh on every request.
        return ['id' => $this->id, 'email' => $this->email, 'passwordHash' => hash('crc32c', $this->passwordHash)];
    }

    /**
     * A session from before ids were UUIDs holds a whole number here. It must
     * still load, or that user gets an error page until the cookie is gone; the
     * firewall replaces this object with a fresh one on the same request.
     *
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        $id = $data['id'] ?? '';
        $email = $data['email'] ?? '';
        $hash = $data['passwordHash'] ?? '';
        if (!\is_string($email) || !\is_string($hash) || !\is_string($id) && !\is_int($id)) {
            throw new \UnexpectedValueException('The session does not hold a user.');
        }
        $this->id = (string) $id;
        $this->email = $email;
        $this->passwordHash = $hash;
        $this->totpSecret = null;
        $this->trustedDeviceVersion = 0;
    }
}
