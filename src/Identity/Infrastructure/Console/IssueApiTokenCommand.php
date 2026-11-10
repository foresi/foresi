<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Console;

use App\Identity\Application\ApiTokenRefused;
use App\Identity\Application\ManageApiTokens;
use App\Identity\Application\UserRepository;
use App\Identity\Domain\ApiScope;
use App\Identity\Domain\ApiToken;
use App\Identity\Domain\User;
use App\Shared\Domain\Id;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The operator issues a token for a person of an organisation. No two-factor
 * sign-in is asked for here: whoever runs the console already holds the
 * installation. The secret is printed once and kept nowhere.
 */
#[AsCommand(name: 'app:api-token:issue', description: 'Issue a personal API token for a user in an organisation')]
final readonly class IssueApiTokenCommand
{
    public function __construct(
        private ManageApiTokens $manage,
        private UserRepository $users,
        private TenantContext $context,
    ) {
    }

    /**
     * @param list<string> $scope
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'E-mail address of the user')]
        string $email,
        #[Option(description: 'Id of the organisation (tenant)')]
        string $tenant = '',
        #[Option(description: 'What the token is for, 1 to 80 characters')]
        string $name = '',
        #[Option(description: 'Days until it expires: 30, 90 or 365')]
        int $days = ManageApiTokens::DEFAULT_EXPIRY_DAYS,
        #[Option(description: 'read or write; repeat for both')]
        array $scope = ['read'],
    ): int {
        if (!Id::isValid($tenant)) {
            $io->error('Pass the id of the organisation with --tenant.');

            return Command::INVALID;
        }
        $scopes = [];
        foreach ($scope as $value) {
            $parsed = ApiScope::tryFrom($value);
            if (null === $parsed) {
                $io->error(\sprintf('Unknown scope "%s", expected read or write.', $value));

                return Command::INVALID;
            }
            $scopes[] = $parsed;
        }
        $user = $this->users->findByEmail(strtolower(trim($email)));
        if (!$user instanceof User) {
            $io->error('No user has this e-mail.');

            return Command::FAILURE;
        }

        $this->context->switchTo(TenantId::fromString($tenant));
        try {
            $issued = $this->manage->issueAsOperator($user->id(), $name, $scopes, $days);
        } catch (ApiTokenRefused $refused) {
            $io->error($this->refusal($refused->reason));

            return Command::FAILURE;
        } finally {
            $this->context->reset();
        }

        $io->success(\sprintf('Issued "%s" for %s, valid until %s (id %s).', $issued->token->name(), $user->email(), $issued->token->expiresAt()->format(\DATE_ATOM), $issued->token->id()));
        $io->writeln($issued->secret);
        $io->warning('Copy the token now: it will not be shown again.');

        return Command::SUCCESS;
    }

    private function refusal(string $reason): string
    {
        return match ($reason) {
            ApiTokenRefused::NOT_STAFF => 'The user is not the owner or a member of this organisation.',
            ApiTokenRefused::NAME => \sprintf('Pass a name of 1 to %d characters with --name.', ApiToken::NAME_MAX_LENGTH),
            ApiTokenRefused::SCOPES => 'Pass at least one scope.',
            ApiTokenRefused::EXPIRY => 'A token is valid for 30, 90 or 365 days.',
            default => \sprintf('The token was refused: %s.', $reason),
        };
    }
}
