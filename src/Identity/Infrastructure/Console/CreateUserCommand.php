<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Console;

use App\Identity\Application\UserRepository;
use App\Identity\Domain\Membership;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Instance\Domain\Tenant;
use App\Instance\Domain\TenantId;
use App\Party\Domain\Party;
use App\Shared\Domain\Id;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/**
 * Creates a person who signs in, with their membership. The first run of an
 * instance also creates its tenant row, which goes with the tenant itself.
 */
#[AsCommand(name: 'app:user:create', description: 'Create a user and their membership')]
final readonly class CreateUserCommand
{
    /** The name the tenant row of a new instance gets. */
    public const string INSTANCE_NAME = 'Foresi';

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

        // One transaction: a failure never leaves a tenant without its first user.
        $this->entityManager->wrapInTransaction(function () use ($user, $membershipRole, $party): void {
            if (!$this->entityManager->getRepository(Tenant::class)->findOneBy([]) instanceof Tenant) {
                $this->entityManager->persist(new Tenant(TenantId::generate(), self::INSTANCE_NAME, new \DateTimeImmutable()));
            }
            $this->entityManager->persist($user);
            $this->entityManager->persist(new Membership($user, $membershipRole, $party));
            $this->entityManager->flush();
        });

        $io->success(sprintf('Created %s as %s.', $email, $membershipRole->value));

        return Command::SUCCESS;
    }
}
