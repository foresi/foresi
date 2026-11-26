<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Infrastructure\Console;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Identity\Application\UserRepository;
use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Foresi\Party\Domain\Party;
use Foresi\Shared\Domain\Id;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/**
 * Creates a person who signs in, with their membership.
 */
#[AsCommand(
    name: 'app:user:create',
    description: 'Create a user and their membership',
    help: <<<'HELP'
    Creates a person who can sign in, together with their membership, in one transaction. Run it once
    after installation to create the first owner, or later to add a user without an invitation. It asks
    for a display name and a password of at least 12 characters.
    A guest must represent an existing contact, so pass --contact with its id.
    It writes one user and one membership. It is not safe to repeat: a second run for the same e-mail
    address fails with exit code 1 and changes nothing.

      bin/console app:user:create owner@example.com owner
    HELP,
)]
final readonly class CreateUserCommand
{
    public function __construct(
        private UserRepository $users,
        private EntityManagerInterface $entityManager,
        private PasswordHasherFactoryInterface $hashers,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'E-mail address, used to sign in')]
        string $email,
        #[Argument(description: 'owner, member or guest')]
        string $role,
        #[Option(description: 'Id of the contact the user represents; required for a guest')]
        ?string $contact = null,
    ): int {
        $membershipRole = Role::tryFrom($role);
        if (null === $membershipRole) {
            $io->error(sprintf('Unknown role "%s", expected owner, member or guest.', $role));

            return Command::INVALID;
        }
        if (Role::Guest === $membershipRole && null === $contact) {
            $io->error('A guest user represents a contact: pass its id with --contact.');

            return Command::INVALID;
        }

        $party = null === $contact || !Id::isValid($contact) ? null : $this->entityManager->find(Party::class, $contact);
        if (null !== $contact && !$party instanceof Party) {
            $io->error(sprintf('There is no contact "%s".', $contact));

            return Command::INVALID;
        }

        $email = strtolower(trim($email));
        if ($this->users->findByEmail($email) instanceof User) {
            $io->error('A user with this e-mail already exists.');

            return Command::FAILURE;
        }

        $defaultName = explode('@', $email)[0];
        $answer = $io->ask('Display name', $defaultName);
        $displayName = \is_string($answer) ? trim($answer) : '';
        $displayName = '' === $displayName ? $defaultName : $displayName;

        $password = $io->askHidden('Password');
        $confirmation = $io->askHidden('Repeat password');
        if (!\is_string($password) || User::MIN_PASSWORD_LENGTH > \strlen($password)) {
            $io->error(sprintf('The password must have at least %d characters.', User::MIN_PASSWORD_LENGTH));

            return Command::INVALID;
        }
        if ($password !== $confirmation) {
            $io->error('The passwords do not match.');

            return Command::INVALID;
        }

        try {
            $user = new User($email, $this->hashers->getPasswordHasher(SecurityUser::class)->hash($password), $displayName, 'cs');
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        // One transaction: a failure never leaves a user without their membership.
        $this->entityManager->wrapInTransaction(function () use ($user, $membershipRole, $party): void {
            $this->entityManager->persist($user);
            $this->entityManager->persist(new Membership($user, $membershipRole, $party));
            $this->entityManager->flush();
        });

        $io->success(sprintf('Created %s as %s.', $email, $membershipRole->value));

        return Command::SUCCESS;
    }
}
