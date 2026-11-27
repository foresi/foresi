<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Infrastructure\Console;

use Foresi\Identity\Application\ApiTokenRefused;
use Foresi\Identity\Application\ManageApiTokens;
use Foresi\Identity\Application\UserRepository;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Identity\Domain\ApiToken;
use Foresi\Identity\Domain\User;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The operator issues a token for a person. No two-factor
 * sign-in is asked for here: whoever runs the console already holds the
 * installation. The secret is printed once and kept nowhere.
 */
#[AsCommand(
    name: 'app:api-token:issue',
    description: 'Issue a personal API token for a user',
    help: <<<'HELP'
    Issues a personal API token for an existing user who is the owner or a team member. Run it by hand
    when someone needs the REST API or the MCP server; the token acts as that person with the scopes you
    give. At least one --scope is required; repeat the option for more. The command writes one token.
    The secret is printed once and stored nowhere, so copy it from the output. Running it again issues
    another token. It exits with 1 when the user is unknown or the request is refused, and with 2 for
    invalid input.

      bin/console app:api-token:issue owner@example.com --name="Reporting" --days=90 --scope=read
    HELP,
)]
final readonly class IssueApiTokenCommand
{
    public function __construct(
        private ManageApiTokens $manage,
        private UserRepository $users,
    ) {
    }

    /**
     * @param list<string> $scope
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'E-mail address of the user')]
        string $email,
        #[Option(description: 'What the token is for, 1 to 80 characters')]
        string $name = '',
        #[Option(description: 'Days until it expires: 30, 90 or 365')]
        int $days = ManageApiTokens::DEFAULT_EXPIRY_DAYS,
        #[Option(description: 'read, write, or a resource such as firm:read or firm:write; repeat for more')]
        array $scope = [],
    ): int {
        if ([] === $scope) {
            $io->error('Pass what the token may do with --scope, such as --scope=read or --scope=contacts:write.');

            return Command::INVALID;
        }
        $scopes = [];
        foreach ($scope as $value) {
            $parsed = ApiScope::tryFrom($value);
            if (!$parsed instanceof ApiScope) {
                $io->error(\sprintf('Unknown scope "%s", expected read, write, or a resource such as firm:write.', $value));

                return Command::INVALID;
            }
            $scopes[] = $parsed;
        }
        $user = $this->users->findByEmail(strtolower(trim($email)));
        if (!$user instanceof User) {
            $io->error('No user has this e-mail.');

            return Command::FAILURE;
        }

        try {
            $issued = $this->manage->issueAsOperator($user->id(), $name, $scopes, $days);
        } catch (ApiTokenRefused $refused) {
            $io->error($this->refusal($refused->reason));

            return Command::FAILURE;
        }

        $io->success(\sprintf('Issued "%s" for %s, valid until %s (id %s).', $issued->token->name(), $user->email(), $issued->token->expiresAt()->format(\DATE_ATOM), $issued->token->id()));
        $io->writeln($issued->secret);
        $io->warning('Copy the token now: it will not be shown again.');

        return Command::SUCCESS;
    }

    private function refusal(string $reason): string
    {
        return match ($reason) {
            ApiTokenRefused::NOT_STAFF => 'The user is not the owner or a team member.',
            ApiTokenRefused::NAME => \sprintf('Pass a name of 1 to %d characters with --name.', ApiToken::NAME_MAX_LENGTH),
            ApiTokenRefused::SCOPES => 'A scope names a resource no installed module declares; bin/console app:modules lists them.',
            ApiTokenRefused::EXPIRY => 'A token is valid for 30, 90 or 365 days.',
            default => \sprintf('The token was refused: %s.', $reason),
        };
    }
}
