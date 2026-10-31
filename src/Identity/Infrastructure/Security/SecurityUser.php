<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Domain\User;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The signed-in identity as the Symfony firewall sees it. Tenant roles are
 * membership data; every authenticated user only has ROLE_USER here.
 */
final readonly class SecurityUser implements UserInterface, PasswordAuthenticatedUserInterface
{
    public function __construct(
        private string $id,
        private string $email,
        private string $passwordHash,
    ) {
    }

    public static function fromUser(User $user): self
    {
        return new self($user->id(), $user->email(), $user->passwordHash());
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
    }
}
