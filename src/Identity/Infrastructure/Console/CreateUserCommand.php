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
use App\Party\Domain\Party;
use App\Shared\Domain\Id;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\Tenant;
use App\Tenancy\Domain\TenantId;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

#[AsCommand(name: 'app:user:create', description: 'Create a user and a membership, creating the tenant if it is missing')]
final readonly class CreateUserCommand
{
    public function __construct(
        private UserRepository $users,
        private EntityManagerInterface $entityManager,
        private TenantContext $context,
        private PasswordHasherFactoryInterface $hashers,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'E-mail address, used to sign in')]
        string $email,
        #[Argument(description: 'Name of the tenant; created when it does not exist yet')]
        string $tenantName,
        #[Argument(description: 'owner, member or guest')]
        string $role,
        #[Option(description: 'Id of the contact the user represents in the tenant; required for a guest')]
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

        $tenantName = trim($tenantName);
        if ('' === $tenantName) {
            $io->error('The tenant name must not be empty.');

            return Command::INVALID;
        }

        $tenant = $this->entityManager->getRepository(Tenant::class)->findOneBy(['name' => $tenantName]);
        $party = null === $contact ? null : $this->contactIn($tenant, $contact);
        if (null !== $contact && !$party instanceof Party) {
            $io->error(sprintf('There is no contact "%s" in "%s".', $contact, $tenantName));

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

        // One transaction: a failure never leaves an orphan tenant behind.
        $this->entityManager->wrapInTransaction(function () use ($tenant, $tenantName, $user, $membershipRole, $party): void {
            if (!$tenant instanceof Tenant) {
                $tenant = new Tenant(TenantId::generate(), $tenantName, new \DateTimeImmutable());
                $this->entityManager->persist($tenant);
                $this->entityManager->flush();
            }

            $this->context->switchTo($tenant->id());
            try {
                $this->entityManager->persist($user);
                $this->entityManager->persist(new Membership($user, $membershipRole, $party instanceof Party ? $this->entityManager->getReference(Party::class, $party->id()) : null));
                $this->entityManager->flush();
            } finally {
                $this->context->reset();
            }
        });

        $io->success(sprintf('Created %s as %s in "%s".', $email, $membershipRole->value, $tenantName));

        return Command::SUCCESS;
    }

    /**
     * A contact of the tenant, which must exist already: a new tenant has none.
     */
    private function contactIn(?Tenant $tenant, string $contact): ?Party
    {
        if (!$tenant instanceof Tenant || !Id::isValid($contact)) {
            return null;
        }
        $this->context->switchTo($tenant->id());
        try {
            return $this->entityManager->find(Party::class, $contact);
        } finally {
            $this->context->reset();
        }
    }
}
