<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Console;

use App\Identity\Application\ApiTokenRefused;
use App\Identity\Infrastructure\Security\ApiTokenLogin;
use App\Identity\Infrastructure\Security\ApiTokenRejected;
use App\Shared\Infrastructure\Mcp\ToolCaller;
use App\Shared\Infrastructure\Mcp\ToolRefusal;
use App\Tenancy\Application\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Signs the MCP server on stdio in with the personal API token in the
 * environment variable FORESI_API_TOKEN, before the server starts; never
 * from an option, which would end up in the shell history and the process
 * list. Without a valid token the command does not run: one line on stderr
 * says why, as stdout belongs to the protocol.
 *
 * The process then serves one client for as long as it runs, so before every
 * tool call the token is checked again, and what Doctrine holds from the
 * previous call is dropped.
 */
final class McpStdioSignIn implements ToolCaller
{
    public const string COMMAND = 'mcp:server';
    public const string VARIABLE = 'FORESI_API_TOKEN';

    private ?string $signedInWith = null;
    private ?ToolRefusal $signedOut = null;

    public function __construct(
        private readonly ApiTokenLogin $login,
        private readonly TokenStorageInterface $tokens,
        private readonly TenantContext $context,
        private readonly EntityManagerInterface $entities,
        #[Autowire(env: 'default::'.self::VARIABLE), \SensitiveParameter]
        private readonly ?string $secret = null,
    ) {
    }

    #[AsEventListener(event: ConsoleEvents::COMMAND)]
    public function onCommand(ConsoleCommandEvent $event): void
    {
        if (self::COMMAND !== $event->getCommand()?->getName()) {
            return;
        }

        $refusal = $this->signIn();
        if (null === $refusal) {
            return;
        }

        $output = $event->getOutput();
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $errors->writeln($refusal);
        $event->disableCommand();
    }

    public function confirm(): void
    {
        if ($this->signedOut instanceof ToolRefusal) {
            throw $this->signedOut;
        }
        if (null === $this->signedInWith) {
            return;
        }

        $this->entities->clear();
        try {
            $this->login->asConsole($this->signedInWith);
        } catch (ApiTokenRefused $refused) {
            $this->signOut();
            // Once refused, the process stays signed out until it is restarted.
            $this->signedOut = new ToolRefusal(ApiTokenRejected::because($refused->reason)->problem(), 'The API token of this connection was refused: '.$this->explain($refused->reason).' Restart the MCP server with a valid token.');

            throw $this->signedOut;
        }
    }

    /**
     * @return string|null why the token was not accepted, or null once signed in
     */
    private function signIn(): ?string
    {
        $secret = trim((string) $this->secret);
        if ('' === $secret) {
            return \sprintf('Set %s to a personal API token (Settings, API) to start the MCP server.', self::VARIABLE);
        }

        try {
            $this->login->asConsole($secret);
        } catch (ApiTokenRefused $refused) {
            $this->signOut();

            return \sprintf('The token in %s was refused: %s', self::VARIABLE, $this->explain($refused->reason));
        }
        $this->signedInWith = $secret;

        return null;
    }

    private function signOut(): void
    {
        $this->signedInWith = null;
        $this->tokens->setToken(null);
        $this->context->reset();
    }

    private function explain(string $reason): string
    {
        return match ($reason) {
            ApiTokenRefused::EXPIRED => 'it has expired.',
            ApiTokenRefused::REVOKED => 'it has been revoked.',
            ApiTokenRefused::MEMBERSHIP_GONE => 'its person no longer works for its organisation.',
            ApiTokenRefused::GUEST => 'guests do not sign in with a token.',
            default => 'it is not a valid Foresi API token.',
        };
    }
}
