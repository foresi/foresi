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
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\Tenant;
use App\Tenancy\Domain\TenantId;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

#[AsCommand(name: 'app:user:create', description: 'Create a user and a membership, creating the tenant if it is missing')]
final readonly class CreateUserCommand
{
    private const int MIN_PASSWORD_LENGTH = 12;

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
        #[Argument(description: 'owner, member, subcontractor or client')]
        string $role,
    ): int {
        $membershipRole = Role::tryFrom($role);
        if (null === $membershipRole) {
            $io->error(sprintf('Unknown role "%s".', $role));

            return Command::INVALID;
        }

        $tenantName = trim($tenantName);
        if ('' === $tenantName) {
            $io->error('The tenant name must not be empty.');

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
        if (!\is_string($password) || self::MIN_PASSWORD_LENGTH > \strlen($password)) {
            $io->error(sprintf('The password must have at least %d characters.', self::MIN_PASSWORD_LENGTH));

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
        $this->entityManager->wrapInTransaction(function () use ($tenantName, $user, $membershipRole): void {
            $tenant = $this->entityManager->getRepository(Tenant::class)->findOneBy(['name' => $tenantName]);
            if (!$tenant instanceof Tenant) {
                $tenant = new Tenant(TenantId::generate(), $tenantName, new \DateTimeImmutable());
                $this->entityManager->persist($tenant);
                $this->entityManager->flush();
            }

            $this->context->switchTo($tenant->id());
            try {
                $this->entityManager->persist($user);
                $this->entityManager->persist(new Membership($user, $membershipRole));
                $this->entityManager->flush();
            } finally {
                $this->context->reset();
            }
        });

        $io->success(sprintf('Created %s as %s in "%s".', $email, $membershipRole->value, $tenantName));

        return Command::SUCCESS;
    }
}
