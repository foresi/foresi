<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Shared\Domain\Id;

/**
 * A person who can log in. What they may do in the organisation is decided
 * by their membership.
 */
#[ORM\Entity]
#[ORM\Table(name: 'app_user')]
#[ORM\Index(name: 'idx_app_user_pending_email_token', columns: ['pending_email_token_hash'])]
class User
{
    public const array LOCALES = ['cs', 'en'];

    /** The shortest password anyone signs in with, chosen on the console or on an invitation. */
    public const int MIN_PASSWORD_LENGTH = 12;

    /** How long the link that confirms a new sign-in address works (OWASP: a short-lived, single-use link). */
    public const int EMAIL_CHANGE_VALID_HOURS = 24;

    /** The longest name a person can give themselves, the length of its column. */
    public const int MAX_DISPLAY_NAME_LENGTH = 255;

    /*
     * The person's appearance, on every device they sign in on and for the whole app (ABRA Flexi,
     * "Vzhled webového rozhraní": a size of the rows and a size of the text, per user). The text
     * size scales every text of the theme; the density sets the height of table rows and, with a
     * mouse, of fields and buttons.
     */
    public const string TEXT_SMALL = 'small';
    public const string TEXT_STANDARD = 'standard';
    public const string TEXT_LARGE = 'large';
    public const array TEXT_SIZES = [self::TEXT_SMALL, self::TEXT_STANDARD, self::TEXT_LARGE];
    public const string DENSITY_COMPACT = 'compact';
    public const string DENSITY_NORMAL = 'normal';
    public const string DENSITY_SPACIOUS = 'spacious';
    public const array DENSITIES = [self::DENSITY_COMPACT, self::DENSITY_NORMAL, self::DENSITY_SPACIOUS];

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

    /** Changes with every new photo, so its address changes too; null without a photo. */
    #[ORM\Column(name: 'photo_version', type: 'string', length: 32, nullable: true)]
    private ?string $photoVersion = null;

    /** The address the person asked to sign in with, until they confirm it from that mailbox. */
    #[ORM\Column(name: 'pending_email', type: 'string', length: 255, nullable: true)]
    private ?string $pendingEmail = null;

    /** SHA-256 of the secret in the confirmation link; the link itself is never kept. */
    #[ORM\Column(name: 'pending_email_token_hash', type: 'string', length: 64, nullable: true)]
    private ?string $pendingEmailTokenHash = null;

    #[ORM\Column(name: 'pending_email_expires_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $pendingEmailExpiresAt = null;

    /** Raised to end every session signed in before, as each one keeps the version it began with. */
    #[ORM\Column(name: 'session_version', type: 'integer', options: ['default' => 0])]
    private int $sessionVersion = 0;

    #[ORM\Column(name: 'text_size', type: 'string', length: 16, options: ['default' => self::TEXT_STANDARD])]
    private string $textSize = self::TEXT_STANDARD;

    #[ORM\Column(type: 'string', length: 16, options: ['default' => self::DENSITY_NORMAL])]
    private string $density = self::DENSITY_NORMAL;

    public function __construct(
        string $email,
        #[ORM\Column(name: 'password_hash', type: 'string', length: 255)]
        private string $passwordHash,
        string $displayName,
        string $locale = 'en',
    ) {
        $this->id = Id::generate();
        $email = $this->usableEmail($email);
        if ('' === $passwordHash) {
            throw new \InvalidArgumentException('A user needs a password hash.');
        }
        $this->assertSupported($locale);

        $this->email = $email;
        $this->displayName = $this->usableName($displayName);
        $this->locale = $locale;
    }

    /**
     * The person's own name, as everyone in the firm sees it.
     *
     * @throws \InvalidArgumentException when it is blank or longer than MAX_DISPLAY_NAME_LENGTH
     */
    public function rename(string $displayName): void
    {
        $this->displayName = $this->usableName($displayName);
    }

    /**
     * A new photo was stored: its address changes, so no browser keeps the old one.
     */
    public function changePhoto(): void
    {
        $this->photoVersion = bin2hex(random_bytes(8));
    }

    /**
     * Back to the initials.
     */
    public function removePhoto(): void
    {
        $this->photoVersion = null;
    }

    public function photoVersion(): ?string
    {
        return $this->photoVersion;
    }

    public function changeLocale(string $locale): void
    {
        $this->assertSupported($locale);
        $this->locale = $locale;
    }

    /**
     * @throws \InvalidArgumentException when it is none of TEXT_SIZES
     */
    public function changeTextSize(string $textSize): void
    {
        if (!\in_array($textSize, self::TEXT_SIZES, true)) {
            throw new \InvalidArgumentException(\sprintf('Unsupported text size "%s".', $textSize));
        }
        $this->textSize = $textSize;
    }

    /**
     * @throws \InvalidArgumentException when it is none of DENSITIES
     */
    public function changeDensity(string $density): void
    {
        if (!\in_array($density, self::DENSITIES, true)) {
            throw new \InvalidArgumentException(\sprintf('Unsupported density "%s".', $density));
        }
        $this->density = $density;
    }

    public function textSize(): string
    {
        return $this->textSize;
    }

    public function density(): string
    {
        return $this->density;
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
        $this->forgetTrustedDevices();
    }

    /**
     * Every browser trusted to skip the code asks for one again on the next sign-in;
     * the two-factor sign-in itself stays as it is.
     */
    public function forgetTrustedDevices(): void
    {
        ++$this->trustedDeviceVersion;
    }

    /**
     * A new password, hashed by the caller. Whoever knew the old one may have
     * marked a browser as trusted, so those browsers are forgotten too (OWASP:
     * a password change ends what the old password opened).
     */
    public function changePassword(string $passwordHash): void
    {
        if ('' === $passwordHash) {
            throw new \InvalidArgumentException('A user needs a password hash.');
        }
        $this->passwordHash = $passwordHash;
        $this->forgetTrustedDevices();
    }

    /**
     * The person asked to sign in with another address. It stays a request until
     * the link sent there is opened, so the address they sign in with does not
     * change on a typo or on someone else's word (OWASP: confirm a new address).
     * A new request replaces the one before, and its link.
     *
     * @throws \InvalidArgumentException when the address is not valid or is the current one
     */
    public function requestEmailChange(string $newEmail, string $tokenHash, \DateTimeImmutable $expiresAt): void
    {
        $newEmail = $this->usableEmail($newEmail);
        if ($newEmail === $this->email) {
            throw new \InvalidArgumentException('The new address is the current one.');
        }
        if ('' === $tokenHash) {
            throw new \InvalidArgumentException('An e-mail change needs the hash of its link.');
        }
        $this->pendingEmail = $newEmail;
        $this->pendingEmailTokenHash = $tokenHash;
        $this->pendingEmailExpiresAt = $expiresAt;
    }

    public function cancelEmailChange(): void
    {
        $this->pendingEmail = null;
        $this->pendingEmailTokenHash = null;
        $this->pendingEmailExpiresAt = null;
    }

    /**
     * The address waiting for confirmation; null when there is none or its link has expired.
     */
    public function pendingEmail(\DateTimeImmutable $now): ?string
    {
        return $this->pendingEmailExpiresAt instanceof \DateTimeImmutable && $now < $this->pendingEmailExpiresAt ? $this->pendingEmail : null;
    }

    public function pendingEmailExpiresAt(): ?\DateTimeImmutable
    {
        return $this->pendingEmailExpiresAt;
    }

    /**
     * The link was opened: the person signs in with the new address from now on,
     * and every session that began before ends.
     *
     * @return string the address they signed in with before
     *
     * @throws \LogicException when no change waits or its link has expired
     */
    public function confirmEmailChange(\DateTimeImmutable $now): string
    {
        $newEmail = $this->pendingEmail($now) ?? throw new \LogicException('No e-mail change waits for confirmation.');
        $oldEmail = $this->email;
        $this->email = $newEmail;
        $this->cancelEmailChange();
        $this->endSessions();

        return $oldEmail;
    }

    /**
     * Every session signed in until now ends on its next request.
     */
    public function endSessions(): void
    {
        ++$this->sessionVersion;
    }

    public function sessionVersion(): int
    {
        return $this->sessionVersion;
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

    private function usableEmail(string $email): string
    {
        $email = strtolower(trim($email));
        if (false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('A user needs a valid e-mail address.');
        }

        return $email;
    }

    private function usableName(string $displayName): string
    {
        $displayName = trim($displayName);
        if ('' === $displayName) {
            throw new \InvalidArgumentException('A user needs a display name.');
        }
        if (mb_strlen($displayName) > self::MAX_DISPLAY_NAME_LENGTH) {
            throw new \InvalidArgumentException(sprintf('A display name has at most %d characters.', self::MAX_DISPLAY_NAME_LENGTH));
        }

        return $displayName;
    }

    private function assertSupported(string $locale): void
    {
        if (!\in_array($locale, self::LOCALES, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported locale "%s".', $locale));
        }
    }
}
